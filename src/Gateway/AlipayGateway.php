<?php

namespace WPKJFluentCart\Alipay\Gateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\Helper as FluentCartHelper;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;
use WPKJFluentCart\Alipay\Webhook\NotifyHandler;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

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
    public array $supportedFeatures = ['payment', 'refund', 'webhook'];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(
            new AlipaySettingsBase()
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
            'description' => __('Pay securely with Alipay - Support PC, Mobile WAP, and In-App payments', 'wpkj-fluentcart-alipay-payment'),
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
     * @return void
     */
    public function boot()
    {
        // Register webhook handler
        add_action('init', function() {
            if (isset($_GET['fct_payment_listener']) && isset($_GET['method']) && $_GET['method'] === 'alipay') {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $this->handleIPN();
                }
            }
        });
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
            'message' => __('Ready to process payment', 'wpkj-fluentcart-alipay-payment'),
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
                'label' => __('Alipay', 'wpkj-fluentcart-alipay-payment'),
                'type' => 'notice'
            ],
            'payment_mode' => [
                'type' => 'tabs',
                'schema' => [
                    [
                        'type' => 'tab',
                        'label' => __('Live Credentials', 'wpkj-fluentcart-alipay-payment'),
                        'value' => 'live',
                        'schema' => [
                            'live_app_id' => [
                                'type' => 'text',
                                'label' => __('App ID', 'wpkj-fluentcart-alipay-payment'),
                                'placeholder' => '2021xxxxxxxxxxxx',
                                'required' => true,
                                'help' => __('Your Alipay application ID (16 digits)', 'wpkj-fluentcart-alipay-payment')
                            ],
                            'live_private_key' => [
                                'type' => 'password',
                                'label' => __('Application Private Key', 'wpkj-fluentcart-alipay-payment'),
                                'placeholder' => 'MIIEvQIBADANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Your application RSA2 private key (paste without header/footer)', 'wpkj-fluentcart-alipay-payment')
                            ],
                            'live_alipay_public_key' => [
                                'type' => 'password',
                                'label' => __('Alipay Public Key', 'wpkj-fluentcart-alipay-payment'),
                                'placeholder' => 'MIIBIjANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Alipay RSA2 public key (paste without header/footer)', 'wpkj-fluentcart-alipay-payment')
                            ]
                        ]
                    ],
                    [
                        'type' => 'tab',
                        'label' => __('Test Credentials', 'wpkj-fluentcart-alipay-payment'),
                        'value' => 'test',
                        'schema' => [
                            'test_app_id' => [
                                'type' => 'text',
                                'label' => __('App ID', 'wpkj-fluentcart-alipay-payment'),
                                'placeholder' => '9021xxxxxxxxxxxx',
                                'required' => true,
                                'help' => __('Your Alipay sandbox application ID (16 digits)', 'wpkj-fluentcart-alipay-payment')
                            ],
                            'test_private_key' => [
                                'type' => 'password',
                                'label' => __('Application Private Key', 'wpkj-fluentcart-alipay-payment'),
                                'placeholder' => 'MIIEvQIBADANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Your application RSA2 private key for sandbox (paste without header/footer)', 'wpkj-fluentcart-alipay-payment')
                            ],
                            'test_alipay_public_key' => [
                                'type' => 'password',
                                'label' => __('Alipay Public Key', 'wpkj-fluentcart-alipay-payment'),
                                'placeholder' => 'MIIBIjANBgkqhkiG9w0B...',
                                'required' => true,
                                'help' => __('Alipay RSA2 public key for sandbox (paste without header/footer)', 'wpkj-fluentcart-alipay-payment')
                            ]
                        ]
                    ]
                ]
            ],
            'notify_url_info' => [
                'type' => 'html_attr',
                'label' => __('Notify URL (Webhook)', 'wpkj-fluentcart-alipay-payment'),
                'value' => sprintf(
                    '<div class="mt-3"><p class="mb-2">%s</p><code class="copyable-content">%s</code><p class="mt-2 text-sm text-gray-600">%s</p></div>',
                    esc_html__('Configure this URL in your Alipay application settings:', 'wpkj-fluentcart-alipay-payment'),
                    esc_html($notifyUrl),
                    esc_html__('This URL will receive payment notifications from Alipay.', 'wpkj-fluentcart-alipay-payment')
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

        if (empty($appId) || empty($privateKey) || empty($alipayPublicKey)) {
            return [
                'status' => 'failed',
                'message' => __('All credential fields are required!', 'wpkj-fluentcart-alipay-payment')
            ];
        }

        // Validate App ID format
        if (!preg_match('/^\d{16}$/', $appId)) {
            return [
                'status' => 'failed',
                'message' => __('Invalid App ID format. It should be 16 digits.', 'wpkj-fluentcart-alipay-payment')
            ];
        }

        return [
            'status' => 'success',
            'message' => __('Credentials validated successfully!', 'wpkj-fluentcart-alipay-payment')
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
            $data["{$mode}_private_key"] = FluentCartHelper::encryptKey($data["{$mode}_private_key"]);
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
    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'alipay_refund_error',
                __('Refund amount is required.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        try {
            $processor = new PaymentProcessor($this->settings);
            $api = new \WPKJFluentCart\Alipay\API\AlipayAPI($this->settings);

            $outTradeNo = Helper::generateOutTradeNo($transaction->uuid);
            $refundAmount = Helper::toDecimal($amount);

            $result = $api->refund([
                'out_trade_no' => $outTradeNo,
                'refund_amount' => $refundAmount,
                'out_request_no' => $transaction->uuid . '-' . time(),
                'refund_reason' => Arr::get($args, 'reason', 'Customer requested refund')
            ]);

            if (is_wp_error($result)) {
                return $result;
            }

            Logger::info('Refund Successful', [
                'transaction_uuid' => $transaction->uuid,
                'amount' => $refundAmount
            ]);

            return $result;

        } catch (\Exception $e) {
            Logger::error('Refund Error', $e->getMessage());
            return new \WP_Error('alipay_refund_error', $e->getMessage());
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
                'message' => __('Alipay does not support the currency you are using!', 'wpkj-fluentcart-alipay-payment')
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
        return [
            'CNY', 'USD', 'EUR', 'GBP', 'HKD', 'JPY', 'KRW', 
            'SGD', 'AUD', 'CAD', 'CHF', 'NZD', 'THB', 'MYR'
        ];
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
                'src' => WPKJ_FC_ALIPAY_URL . 'assets/js/checkout.js',
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
        return [
            'wpkj_fc_alipay_data' => [
                'translations' => [
                    'processing' => __('Processing payment...', 'wpkj-fluentcart-alipay-payment'),
                    'redirecting' => __('Redirecting to Alipay...', 'wpkj-fluentcart-alipay-payment'),
                    'error' => __('Payment error occurred', 'wpkj-fluentcart-alipay-payment')
                ]
            ]
        ];
    }
}
