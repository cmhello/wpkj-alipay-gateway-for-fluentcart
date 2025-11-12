<?php

namespace WPKJFluentCart\Alipay\Gateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\Helper as FluentCartHelper;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;
use WPKJFluentCart\Alipay\Subscription\AlipaySubscriptions;
use WPKJFluentCart\Alipay\Subscription\AlipaySubscriptionProcessor;
use WPKJFluentCart\Alipay\Webhook\NotifyHandler;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;
use WPKJFluentCart\Alipay\Utils\TransactionHelper;

/**
 * Alipay Payment Gateway
 * 
 * Main gateway class for Alipay integration with FluentCart
 */
class AlipayGateway extends AbstractPaymentGateway
{
    /**
     * Gateway slug
     * 
     * @var string
     */
    private $methodSlug = 'alipay';

    /**
     * Supported features
     * 
     * @var array
     */
    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'subscriptions'];

    /**
     * Constructor
     */
    public function __construct()
    {
        $settings = new AlipaySettingsBase();
        $subscriptions = new AlipaySubscriptions($settings);
        
        parent::__construct(
            $settings,
            $subscriptions
        );
    }

    /**
     * Gateway metadata
     * 
     * @return array
     */
    public function meta(): array
    {
        return [
            'title' => 'Alipay',
            'route' => 'alipay',
            'slug' => 'alipay',
            'label' => 'Alipay',
            'description' => esc_html__('Pay securely with Alipay - Support PC, Mobile WAP, and In-App payments', 'wpkj-alipay-gateway-for-fluentcart'),
            'logo' => WPKJ_FC_ALIPAY_URL . 'assets/images/alipay-logo.svg',
            'icon' => WPKJ_FC_ALIPAY_URL . 'assets/images/alipay-icon.svg',
            'brand_color' => '#1678FF',
            'status' => $this->settings->get('is_active') === 'yes',
            'upcoming' => false,
            'supported_features' => $this->supportedFeatures
        ];
    }

    /**
     * Boot the gateway
     * 
     * This method is called during gateway registration.
     * We check for Alipay return parameters and trigger the handler immediately.
     * 
     * @return void
     */
    public function boot()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Alipay return URL verification handled by signature
        if (!empty($_GET['trx_hash']) && 
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Alipay return URL verification handled by signature
            !empty($_GET['fct_redirect']) && 
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Alipay return URL verification handled by signature
            sanitize_text_field(wp_unslash($_GET['fct_redirect'])) === 'yes' &&
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Alipay return URL verification handled by signature
            (!empty($_GET['sign']) || !empty($_GET['out_trade_no']))) {
            
            $returnHandler = new \WPKJFluentCart\Alipay\Webhook\ReturnHandler();
            $returnHandler->handleReturn();
        }
    }

    /**
     * Make payment from payment instance
     * 
     * @param PaymentInstance $paymentInstance Payment instance
     * @return array Payment response
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        try {
            // Check if this is a subscription payment
            if ($paymentInstance->subscription) {
                Logger::info('Processing Subscription Payment', [
                    'order_id' => $paymentInstance->order->id,
                    'order_type' => $paymentInstance->order->type,
                    'subscription_id' => $paymentInstance->subscription->id
                ]);
                
                $subscriptionProcessor = new AlipaySubscriptionProcessor($this->settings);
                return $subscriptionProcessor->processSubscription($paymentInstance);
            }
            
            // Regular single payment
            $processor = new PaymentProcessor($this->settings);
            return $processor->processSinglePayment($paymentInstance);

        } catch (\Exception $e) {
            Logger::error('Payment Processing Exception', $e->getMessage());
            
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle IPN/Webhook notification
     * 
     * @return void
     */
    public function handleIPN()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $notifyHandler = new NotifyHandler();
        $notifyHandler->processNotify();
    }

    /**
     * Get order information for checkout
     * 
     * @param array $data Request data
     * @return void
     */
    public function getOrderInfo(array $data)
    {
        $checkOutHelper = CartCheckoutHelper::make();
        $items = $checkOutHelper->getItems();

        // Validate currency support
        $this->checkCurrencySupport();

        wp_send_json([
            'status' => 'success',
            'message' => __('Ready to process payment', 'wpkj-alipay-gateway-for-fluentcart'),
            'data' => [
                'gateway' => 'alipay',
                'currency' => CurrencySettings::get('currency')
            ]
        ], 200);
    }

    /**
     * Settings form fields
     * 
     * @return array
     */
    public function fields()
    {
        $notifyUrl = add_query_arg([
            'fct_payment_listener' => '1',
            'method' => 'alipay'
        ], site_url('/'));

        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Alipay', 'wpkj-alipay-gateway-for-fluentcart'),
                'type' => 'notice'
            ],
            'gateway_description' => [
                'type' => 'text',
                'label' => __('Gateway Description', 'wpkj-alipay-gateway-for-fluentcart'),
                'placeholder' => __('Pay securely with Alipay - Support PC, Mobile WAP, and In-App payments', 'wpkj-alipay-gateway-for-fluentcart'),
                'help' => __('This description will be displayed on the checkout page when customers select Alipay. Leave empty to use default.', 'wpkj-alipay-gateway-for-fluentcart')
            ],
            'payment_mode' => [
                'type' => 'tabs',
                'schema' => [
                    [
                        'type' => 'tab',
                        'label' => __('Live Credentials', 'wpkj-alipay-gateway-for-fluentcart'),
                        'value' => 'live',
                        'schema' => [
                            'live_app_id' => [
                                'type' => 'text',
                                'label' => __('App ID', 'wpkj-alipay-gateway-for-fluentcart'),
                                'placeholder' => '2021xxxxxxxxxxxx',
                                'required' => true,
                                'help' => __('Your Alipay application ID (16 digits)', 'wpkj-alipay-gateway-for-fluentcart')
                            ],
                            'live_private_key' => [
                                'type' => 'password',
                                'label' => __('Application Private Key', 'wpkj-alipay-gateway-for-fluentcart'),
                                'placeholder' => 'MIIEvQIBADANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Your application RSA2 private key (paste without header/footer)', 'wpkj-alipay-gateway-for-fluentcart')
                            ],
                            'live_alipay_public_key' => [
                                'type' => 'password',
                                'label' => __('Alipay Public Key', 'wpkj-alipay-gateway-for-fluentcart'),
                                'placeholder' => 'MIIBIjANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Alipay RSA2 public key (paste without header/footer)', 'wpkj-alipay-gateway-for-fluentcart')
                            ]
                        ]
                    ],
                    [
                        'type' => 'tab',
                        'label' => __('Test Credentials', 'wpkj-alipay-gateway-for-fluentcart'),
                        'value' => 'test',
                        'schema' => [
                            'test_app_id' => [
                                'type' => 'text',
                                'label' => __('App ID', 'wpkj-alipay-gateway-for-fluentcart'),
                                'placeholder' => '9021xxxxxxxxxxxx',
                                'required' => true,
                                'help' => __('Your Alipay sandbox application ID (16 digits)', 'wpkj-alipay-gateway-for-fluentcart')
                            ],
                            'test_private_key' => [
                                'type' => 'password',
                                'label' => __('Application Private Key', 'wpkj-alipay-gateway-for-fluentcart'),
                                'placeholder' => 'MIIEvQIBADANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Your application RSA2 private key for sandbox (paste without header/footer)', 'wpkj-alipay-gateway-for-fluentcart')
                            ],
                            'test_alipay_public_key' => [
                                'type' => 'password',
                                'label' => __('Alipay Public Key', 'wpkj-alipay-gateway-for-fluentcart'),
                                'placeholder' => 'MIIBIjANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Alipay RSA2 public key for sandbox (paste without header/footer)', 'wpkj-alipay-gateway-for-fluentcart')
                            ]
                        ]
                    ]
                ]
            ],
            'notify_url_info' => [
                'type' => 'html_attr',
                'label' => __('Notify URL (Webhook)', 'wpkj-alipay-gateway-for-fluentcart'),
                'value' => sprintf(
                    '<div class="mt-3"><p class="mb-2">%s</p><code class="copyable-content">%s</code><p class="mt-2 text-sm text-gray-600">%s</p></div>',
                    esc_html__('Configure this URL in your Alipay application settings:', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html($notifyUrl),
                    esc_html__('This URL will receive payment notifications from Alipay.', 'wpkj-alipay-gateway-for-fluentcart')
                )
            ],
            'auto_refund_on_cancel' => [
                'type' => 'checkbox',
                'label' => __('Enable automatic refund when order is cancelled', 'wpkj-alipay-gateway-for-fluentcart')
            ],
            'enable_face_to_face_pc' => [
                'type' => 'checkbox',
                'label' => __('Enable Face-to-Face QR code payment for PC/Desktop (Scan with Alipay app)', 'wpkj-alipay-gateway-for-fluentcart')
            ],
            'subscription_settings_header' => [
                'type' => 'html_attr',
                'label' => __('Subscription Settings', 'wpkj-alipay-gateway-for-fluentcart'),
                'value' => '<hr class="my-4"><h3 class="text-lg font-semibold mb-2">' . esc_html__('Subscription & Recurring Payment', 'wpkj-alipay-gateway-for-fluentcart') . '</h3>'
            ],
            'enable_recurring_agreement' => [
                'type' => 'checkbox',
                'label' => __('Enable automatic recurring payment (⚠️ Requires Alipay Recurring Payment service)', 'wpkj-alipay-gateway-for-fluentcart')
            ],
            'recurring_personal_product_code' => [
                'type' => 'text',
                'label' => __('Personal Product Code', 'wpkj-alipay-gateway-for-fluentcart'),
                'placeholder' => 'GENERAL_WITHHOLDING_P',
                'help' => __('Product code provided by Alipay after signing recurring payment contract. Common values: GENERAL_WITHHOLDING_P (general withholding). Leave empty if not using recurring agreement.', 'wpkj-alipay-gateway-for-fluentcart'),
                'dependency' => [
                    'depends_on' => 'enable_recurring_agreement',
                    'value' => 'yes'
                ]
            ],
            'recurring_info' => [
                'type' => 'html_attr',
                'label' => __('How It Works', 'wpkj-alipay-gateway-for-fluentcart'),
                'value' => sprintf(
                    '<div class="mt-2 p-4 bg-blue-50 border border-blue-200 rounded">'
                    . '<p class="text-sm mb-2"><strong>%s</strong></p>'
                    . '<ul class="text-sm list-disc list-inside space-y-1">'
                    . '<li>%s</li>'
                    . '<li>%s</li>'
                    . '<li>%s</li>'
                    . '<li>%s</li>'
                    . '</ul>'
                    . '<p class="text-sm mt-3 text-gray-600">%s</p>'
                    . '</div>',
                    esc_html__('Alipay Recurring Agreement Process:', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Initial purchase: Customer signs recurring agreement + first payment', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Auto renewal: System automatically deducts payment on billing date', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Cancellation: Customer can cancel agreement anytime from their Alipay app', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Fallback: If agreement deduction fails, customer receives manual payment notification', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Note: Without this feature, all renewals require manual payment by customer.', 'wpkj-alipay-gateway-for-fluentcart')
                ),
                'dependency' => [
                    'depends_on' => 'enable_recurring_agreement',
                    'value' => 'yes'
                ]
            ],
            'auto_cancel_subscription_on_order_cancel' => [
                'type' => 'checkbox',
                'label' => __('Automatically cancel subscription when parent order is canceled (Initial order only)', 'wpkj-alipay-gateway-for-fluentcart'),
                'help' => __('When enabled, canceling the initial subscription order will automatically cancel the associated subscription. This does NOT affect renewal orders - canceling a renewal order will never cancel the subscription.', 'wpkj-alipay-gateway-for-fluentcart')
            ],
            'subscription_cancel_info' => [
                'type' => 'html_attr',
                'label' => __('Order Cancellation Rules', 'wpkj-alipay-gateway-for-fluentcart'),
                'value' => sprintf(
                    '<div class="mt-2 p-4 bg-amber-50 border border-amber-200 rounded">'
                    . '<p class="text-sm mb-2"><strong>%s</strong></p>'
                    . '<ul class="text-sm list-disc list-inside space-y-1">'
                    . '<li>%s</li>'
                    . '<li>%s</li>'
                    . '<li>%s</li>'
                    . '<li>%s</li>'
                    . '</ul>'
                    . '<p class="text-sm mt-3 text-blue-600"><strong>%s</strong> %s</p>'
                    . '<p class="text-sm mt-2 text-gray-600"><strong>%s</strong> %s</p>'
                    . '</div>',
                    esc_html__('Automatic Subscription Cancellation Rules:', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('✅ WILL cancel: Initial subscription order (type=subscription) is canceled', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('❌ Will NOT cancel: Renewal order (type=renewal) is canceled', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('❌ Will NOT cancel: Subscription already in canceled status', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('❌ Will NOT cancel: Orders from other payment methods', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Note:', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('This feature helps maintain data consistency between orders and subscriptions. All operations are logged in the order activity log.', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Default:', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('This feature is DISABLED by default. Enable it only if you need automatic synchronization between order and subscription status.', 'wpkj-alipay-gateway-for-fluentcart')
                )
            ]
        ];
    }

    /**
     * Validate settings before save
     * 
     * @param array $data Settings data
     * @return array Validation result
     */
    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        $appId = Arr::get($data, "{$mode}_app_id");
        $privateKey = Arr::get($data, "{$mode}_private_key");
        $alipayPublicKey = Arr::get($data, "{$mode}_alipay_public_key");

        // Check if App ID is provided
        if (empty($appId)) {
            return [
                'status' => 'failed',
                'message' => __('App ID is required!', 'wpkj-alipay-gateway-for-fluentcart')
            ];
        }

        // Validate App ID format (16 digits)
        if (!preg_match('/^\d{16}$/', $appId)) {
            return [
                'status' => 'failed',
                'message' => __('Invalid App ID format. It should be 16 digits.', 'wpkj-alipay-gateway-for-fluentcart')
            ];
        }

        // Only validate Private Key if it's being changed (not encrypted)
        // FluentCart's password fields send empty string when not modified, or encrypted value
        if (!empty($privateKey)) {
            // Check if this is an encrypted value (FluentCart encrypted keys are very long base64)
            // Encrypted values are typically 2000+ characters and only contain base64 characters
            $isEncrypted = strlen($privateKey) > 2000 && preg_match('/^[A-Za-z0-9+\/]+=*$/', $privateKey) && !preg_match('/^MII/', $privateKey);
            
            // Only validate format if it's a new/updated key (not encrypted)
            if (!$isEncrypted) {
                $cleanPrivateKey = str_replace(["\r", "\n", ' ', '-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'], '', $privateKey);
                
                if (!preg_match('/^MII[A-Za-z0-9+\/=]+$/', $cleanPrivateKey)) {
                    return [
                        'status' => 'failed',
                        'message' => __('Invalid Private Key format. Please paste the RSA2 key content without header/footer.', 'wpkj-alipay-gateway-for-fluentcart')
                    ];
                }

                // Validate key length (RSA2 private key should be at least 1500 characters)
                if (strlen($cleanPrivateKey) < 1500) {
                    return [
                        'status' => 'failed',
                        'message' => __('Private Key appears to be too short. Please ensure you are using RSA2 (2048-bit) key.', 'wpkj-alipay-gateway-for-fluentcart')
                    ];
                }
            }
        }

        // Only validate Alipay Public Key if it's being changed
        if (!empty($alipayPublicKey)) {
            // Check if this is an encrypted value
            $isEncrypted = strlen($alipayPublicKey) > 2000 && preg_match('/^[A-Za-z0-9+\/]+=*$/', $alipayPublicKey) && !preg_match('/^MII/', $alipayPublicKey);
            
            if (!$isEncrypted) {
                $cleanPublicKey = str_replace(["\r", "\n", ' ', '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $alipayPublicKey);
                
                if (!preg_match('/^MII[A-Za-z0-9+\/=]+$/', $cleanPublicKey)) {
                    return [
                        'status' => 'failed',
                        'message' => __('Invalid Alipay Public Key format. Please paste the RSA2 public key content without header/footer.', 'wpkj-alipay-gateway-for-fluentcart')
                    ];
                }
            }
        }

        return [
            'status' => 'success',
            'message' => __('Credentials validated successfully!', 'wpkj-alipay-gateway-for-fluentcart')
        ];
    }

    /**
     * Before settings update
     * 
     * @param array $data New settings
     * @param array $oldSettings Old settings
     * @return array Modified settings
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        // Clean up display-only fields
        $fieldsToRemove = [
            'notice',
            'notify_url_info',
        ];
        
        foreach ($fieldsToRemove as $field) {
            if (isset($data[$field])) {
                unset($data[$field]);
            }
        }

        // Encrypt private keys using FluentCart's default encryption
        $mode = Arr::get($data, 'payment_mode', 'test');
        
        if (!empty($data["{$mode}_private_key"])) {
            // Check if the key is already encrypted
            // Encrypted values are typically 2000+ characters and only contain base64 characters
            $isEncrypted = strlen($data["{$mode}_private_key"]) > 2000 
                && preg_match('/^[A-Za-z0-9+\/]+=*$/', $data["{$mode}_private_key"]) 
                && !preg_match('/^MII/', $data["{$mode}_private_key"]);
            
            // Only encrypt if it's a new value (not already encrypted)
            if (!$isEncrypted) {
                $encrypted = FluentCartHelper::encryptKey($data["{$mode}_private_key"]);
                
                // Verify encryption succeeded
                if (empty($encrypted)) {
                    Logger::error('Private Key Encryption Failed', [
                        'mode' => $mode,
                        'original_key_length' => strlen($data["{$mode}_private_key"])
                    ]);
                    throw new \Exception(
                        esc_html__('Failed to encrypt private key. Please try again.', 'wpkj-alipay-gateway-for-fluentcart')
                    );
                }
                
                $data["{$mode}_private_key"] = $encrypted;
                
                Logger::info('Private Key Encrypted Successfully', [
                    'mode' => $mode,
                    'encrypted_length' => strlen($encrypted)
                ]);
            } else {
                // Key is already encrypted, keep it as is
                Logger::info('Private Key Already Encrypted', [
                    'mode' => $mode
                ]);
            }
        } else {
            // No private key in data, restore from old settings if exists
            if (!empty($oldSettings["{$mode}_private_key"])) {
                $data["{$mode}_private_key"] = $oldSettings["{$mode}_private_key"];
                Logger::info('Private Key Restored from Old Settings', [
                    'mode' => $mode
                ]);
            }
        }

        return $data;
    }

    /**
     * Process refund
     * 
     * @param object $transaction Transaction object
     * @param int $amount Refund amount in cents
     * @param array $args Additional arguments
     * @return array|\WP_Error Refund result
     */
    /**
     * Process refund through Alipay API
     * 
     * This method is called by FluentCart's Refund service when processing refunds.
     * It only handles the Alipay API call and returns the vendor refund ID.
     * FluentCart automatically handles transaction creation, order updates, and events.
     * 
     * @param \FluentCart\App\Models\OrderTransaction $transaction Original payment transaction
     * @param int $amount Refund amount in cents
     * @param array $args Additional arguments (reason, etc.)
     * @return string|\WP_Error Alipay refund ID (trade_no) or WP_Error on failure
     */
    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount || $amount <= 0) {
            return new \WP_Error(
                'invalid_refund_amount',
                __('Refund amount is required and must be greater than zero.', 'wpkj-alipay-gateway-for-fluentcart')
            );
        }

        try {
            $api = new \WPKJFluentCart\Alipay\API\AlipayAPI($this->settings);
            
            // Convert amount from cents to decimal
            $refundAmountDecimal = Helper::toDecimal($amount);
            
            // Get identifiers from transaction
            $outTradeNo = $transaction->meta['out_trade_no'] ?? null;
            $tradeNo = $transaction->vendor_charge_id;
            
            // Validate we have at least one identifier
            if (empty($outTradeNo) && empty($tradeNo)) {
                Logger::error('Missing Transaction Identifiers for Refund', [
                    'transaction_id' => $transaction->id,
                    'transaction_uuid' => $transaction->uuid
                ]);
                
                return new \WP_Error(
                    'missing_transaction_id',
                    __('Cannot process refund: missing both trade_no and out_trade_no', 'wpkj-alipay-gateway-for-fluentcart')
                );
            }
            
            // Generate unique refund request number
            $outRequestNo = $transaction->uuid . '-' . time() . '-' . substr(md5(uniqid()), 0, 8);
            
            $refundParams = [
                'refund_amount' => $refundAmountDecimal,
                'out_request_no' => $outRequestNo,
                'refund_reason' => $args['reason'] ?? 'Order refund'
            ];
            
            // Prefer trade_no over out_trade_no for better success rate
            if (!empty($tradeNo)) {
                $refundParams['trade_no'] = $tradeNo;
            } else {
                $refundParams['out_trade_no'] = $outTradeNo;
            }
            
            Logger::info('Processing Alipay Refund via FluentCart Standard Service', [
                'transaction_id' => $transaction->id,
                'refund_amount' => $refundAmountDecimal,
                'out_request_no' => $outRequestNo,
                'using_trade_no' => !empty($tradeNo)
            ]);
            
            // Call Alipay refund API
            $result = $api->refund($refundParams);
            
            if (is_wp_error($result)) {
                Logger::error('Alipay Refund API Error', [
                    'transaction_id' => $transaction->id,
                    'error' => $result->get_error_message()
                ]);
                return $result;
            }
            
            // Validate response structure
            $responseKey = 'alipay_trade_refund_response';
            if (!isset($result[$responseKey])) {
                Logger::error('Invalid Alipay Refund Response Structure', [
                    'transaction_id' => $transaction->id
                ]);
                
                return new \WP_Error(
                    'invalid_response',
                    __('Invalid response from Alipay', 'wpkj-alipay-gateway-for-fluentcart')
                );
            }
            
            $refundResponse = $result[$responseKey];
            
            // Check business result code
            if (!isset($refundResponse['code']) || $refundResponse['code'] !== '10000') {
                $errorMsg = $refundResponse['sub_msg'] ?? $refundResponse['msg'] ?? 'Unknown error';
                
                Logger::error('Alipay Refund Failed', [
                    'transaction_id' => $transaction->id,
                    'code' => $refundResponse['code'] ?? 'unknown',
                    'message' => $errorMsg
                ]);
                
                return new \WP_Error(
                    'refund_failed',
                    sprintf(
                        /* translators: %s: Alipay error message */
                        __('Alipay refund failed: %s', 'wpkj-alipay-gateway-for-fluentcart'),
                        $errorMsg
                    )
                );
            }
            
            // Check fund_change status
            if (isset($refundResponse['fund_change']) && $refundResponse['fund_change'] === 'N') {
                Logger::warning('Alipay Refund Succeeded But No Fund Change', [
                    'transaction_id' => $transaction->id,
                    'trade_no' => $refundResponse['trade_no'] ?? ''
                ]);
            }
            
            // Return Alipay's trade_no as vendor refund ID
            // FluentCart will automatically use this to populate vendor_charge_id in refund transaction
            $vendorRefundId = $refundResponse['trade_no'] ?? $outRequestNo;
            
            Logger::info('Alipay Refund Successful', [
                'transaction_id' => $transaction->id,
                'vendor_refund_id' => $vendorRefundId,
                'fund_change' => $refundResponse['fund_change'] ?? 'unknown'
            ]);
            
            return $vendorRefundId;
            
        } catch (\Exception $e) {
            Logger::error('Alipay Refund Exception', [
                'transaction_id' => $transaction->id,
                'exception' => $e->getMessage()
            ]);
            
            return new \WP_Error(
                'refund_exception',
                sprintf(
                    /* translators: %s: exception message */
                    __('Refund processing error: %s', 'wpkj-alipay-gateway-for-fluentcart'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Get transaction URL
     * 
     * @param string $url Default URL
     * @param array $data Transaction data
     * @return string Transaction URL
     */
    public function getTransactionUrl($url, $data)
    {
        $tradeNo = Arr::get($data, 'vendor_charge_id');
        
        if (!$tradeNo) {
            return $url;
        }

        // Alipay doesn't provide direct transaction URLs
        // Return the trade number for display
        return '#' . $tradeNo;
    }

    /**
     * Check if currency is supported
     * 
     * @return bool
     */
    public function isCurrencySupported(): bool
    {
        $currency = CurrencySettings::get('currency');
        return in_array(strtoupper($currency), self::getSupportedCurrencies());
    }

    /**
     * Check currency support and throw error if not supported
     * 
     * @return void
     */
    private function checkCurrencySupport()
    {
        if (!$this->isCurrencySupported()) {
            wp_send_json([
                'status' => 'failed',
                'message' => __('Alipay does not support the currency you are using!', 'wpkj-alipay-gateway-for-fluentcart')
            ], 422);
        }
    }

    /**
     * Get supported currencies
     * 
     * @return array
     */
    public static function getSupportedCurrencies(): array
    {
        return AlipayConfig::getSupportedCurrencies();
    }

    /**
     * Enqueue frontend scripts
     * 
     * @param string $hasSubscription Has subscription flag
     * @return array
     */
    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'wpkj-fc-alipay-checkout',
                'src' => WPKJ_FC_ALIPAY_URL . 'assets/js/alipay-checkout.js',
                'deps' => ['jquery']
            ]
        ];
    }

    /**
     * Get localized data for scripts
     * 
     * @return array
     */
    public function getLocalizeData(): array
    {
        $customDescription = $this->settings->get('gateway_description');
        $description = !empty($customDescription) 
            ? $customDescription 
            : __('Pay securely with Alipay - Support PC, Mobile WAP, and In-App payments', 'wpkj-alipay-gateway-for-fluentcart');
        
        return [
            'wpkj_fc_alipay_data' => [
                'description' => $description
            ]
        ];
    }
}
