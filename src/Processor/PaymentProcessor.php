<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\PaymentHelper;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Detector\ClientDetector;
use WPKJFluentCart\Alipay\Services\OrderService;
use WPKJFluentCart\Alipay\Services\EncodingService;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;
use FluentCart\Framework\Support\Arr;

/**
 * Payment Processor
 * 
 * Handles payment processing logic for Alipay
 */
class PaymentProcessor
{
    /**
     * Settings instance
     * 
     * @var AlipaySettingsBase
     */
    private $settings;

    /**
     * API instance
     * 
     * @var AlipayAPI
     */
    private $api;

    /**
     * Constructor
     * 
     * @param AlipaySettingsBase $settings Settings instance
     */
    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
        $this->api = new AlipayAPI($settings);
    }

    /**
     * Process single payment
     * 
     * @param PaymentInstance $paymentInstance Payment instance
     * @return array Payment response
     */
    public function processSinglePayment(PaymentInstance $paymentInstance)
    {
        $transaction = $paymentInstance->transaction;
        $order = $paymentInstance->order;

        try {
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                throw new \Exception(
                    __('Transaction has already been completed.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            if (in_array($order->status, ['completed', 'processing'])) {
                Logger::warning('Payment Attempt on Completed Order', [
                    'order_uuid' => $order->uuid,
                    'order_status' => $order->status,
                    'transaction_status' => $transaction->status
                ]);
            }

            $paymentData = $this->buildPaymentData($paymentInstance);

            $paymentMethod = ClientDetector::getPaymentMethod($this->settings);

            if ($paymentMethod === 'alipay.trade.precreate') {
                return $this->processFaceToFacePayment($paymentInstance, $paymentData);
            }

            $result = $this->api->createPayment($paymentData);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // CRITICAL: Save out_trade_no to transaction meta for later queries and refunds
            $transaction->meta = array_merge($transaction->meta ?? [], [
                'out_trade_no' => $paymentData['out_trade_no'],
                'payment_method_type' => 'web'
            ]);
            $transaction->save();

            Logger::info('Payment Initiated', [
                'order_uuid' => $order->uuid,
                'transaction_uuid' => $transaction->uuid,
                'amount' => $paymentData['total_amount'],
                'out_trade_no' => $paymentData['out_trade_no']
            ]);

            return [
                'status' => 'success',
                'nextAction' => 'redirect',
                'actionName' => 'custom',
                'message' => __('Redirecting to Alipay payment gateway...', 'wpkj-fluentcart-alipay-payment'),
                'data' => [
                    'order' => [
                        'uuid' => $order->uuid,
                    ],
                    'transaction' => [
                        'uuid' => $transaction->uuid,
                    ]
                ],
                'redirect_to' => $result['payment_url'],
                'custom_payment_url' => PaymentHelper::getCustomPaymentLink($order->uuid)
            ];

        } catch (\Exception $e) {
            Logger::error('Payment Processing Error', [
                'message' => $e->getMessage(),
                'order_id' => $order->id,
                'transaction_id' => $transaction->id
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Process Face-to-Face payment (QR code)
     * 
     * @param PaymentInstance $paymentInstance Payment instance
     * @param array $paymentData Payment data
     * @return array Payment response
     */
    private function processFaceToFacePayment(PaymentInstance $paymentInstance, array $paymentData)
    {
        $transaction = $paymentInstance->transaction;
        $order = $paymentInstance->order;

        try {
            $result = $this->api->createFaceToFacePayment($paymentData);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // CRITICAL: Save out_trade_no to transaction meta for later queries
            // Since out_trade_no now contains timestamp, we must store it for status checks
            $transaction->meta = array_merge($transaction->meta ?? [], [
                'qr_code' => $result['qr_code'],
                'payment_method_type' => 'face_to_face',
                'out_trade_no' => $paymentData['out_trade_no']
            ]);
            $transaction->save();

            Logger::info('Face-to-Face Payment Initiated', [
                'order_uuid' => $order->uuid,
                'transaction_uuid' => $transaction->uuid,
                'amount' => $paymentData['total_amount']
            ]);

            // Build direct QR code page URL (NOT using custom_checkout)
            $qrPageUrl = home_url('/');
            $qrCodeEncoded = base64_encode($result['qr_code']);
            $qrPageUrl = add_query_arg([
                'fluent-cart' => 'alipay_f2f_payment',
                'order_hash' => $order->uuid,
                'qr_code' => $qrCodeEncoded,
                'trx_uuid' => $transaction->uuid
            ], $qrPageUrl);

            // Return the same format as COD gateway:
            // redirect_to should be the direct URL string
            return [
                'status' => 'success',
                'message' => __('Redirecting to payment page...', 'wpkj-fluentcart-alipay-payment'),
                'redirect_to' => $qrPageUrl
            ];

        } catch (\Exception $e) {
            Logger::error('Face-to-Face Payment Error', [
                'message' => $e->getMessage(),
                'order_id' => $order->id,
                'transaction_id' => $transaction->id
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Build payment data from payment instance
     * 
     * @param PaymentInstance $paymentInstance Payment instance
     * @return array Payment data
     */
    private function buildPaymentData(PaymentInstance $paymentInstance)
    {
        $transaction = $paymentInstance->transaction;
        $order = $paymentInstance->order;

        // Calculate total amount
        $totalAmount = Helper::toDecimal($transaction->total);

        // Validate payment amount
        if ($transaction->total <= 0) {
            throw new \Exception(
                __('Invalid payment amount. Amount must be greater than zero.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        // Check Alipay single transaction limit
        if ($totalAmount > AlipayConfig::MAX_SINGLE_TRANSACTION_AMOUNT) {
            throw new \Exception(
                sprintf(
                    /* translators: %s: maximum transaction amount in CNY */
                    __('Payment amount exceeds Alipay single transaction limit (%s CNY).', 'wpkj-fluentcart-alipay-payment'),
                    number_format(AlipayConfig::MAX_SINGLE_TRANSACTION_AMOUNT)
                )
            );
        }

        // Generate unique out_trade_no with timestamp
        // IMPORTANT: Each payment attempt must have a unique out_trade_no to prevent
        // "transaction info tampered" error when users make repeated orders
        $outTradeNo = Helper::generateOutTradeNo($transaction->uuid);

        // Build subject and body
        $subject = $this->buildSubject($order);
        $body = $this->buildBody($order);

        // Get URLs (build manually to avoid HTML encoding)
        $returnUrl = $this->getReturnUrl($transaction);
        $notifyUrl = $this->getNotifyUrl();

        return [
            'out_trade_no' => $outTradeNo,
            'total_amount' => $totalAmount,
            'subject' => $subject,
            'body' => $body,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'timeout_express' => AlipayConfig::DEFAULT_PAYMENT_TIMEOUT,
        ];
    }

    /**
     * Build payment subject
     * 
     * @param Order $order Order instance
     * @return string Subject
     */
    private function buildSubject($order)
    {
        $items = $order->order_items;
        
        if (count($items) === 1) {
            $item = $items[0];
            $itemTitle = !empty($item->title) ? $item->title : $item->post_title;
            
            // EDD plugin method: check byte length with strlen()
            // Truncate safely using mb_substr() to avoid breaking UTF-8
            if (strlen($itemTitle) > AlipayConfig::MAX_SUBJECT_LENGTH) {
                // Calculate safe character count that won't exceed byte limit
                $itemTitle = mb_substr($itemTitle, 0, floor(AlipayConfig::MAX_SUBJECT_LENGTH / 3));
                // Re-check and trim further if needed
                while (strlen($itemTitle) > AlipayConfig::MAX_SUBJECT_LENGTH - 3) {
                    $itemTitle = mb_substr($itemTitle, 0, mb_strlen($itemTitle, 'UTF-8') - 1, 'UTF-8');
                }
                $itemTitle .= '...';
            }
            
            return $itemTitle;
        }

        $siteName = get_bloginfo('name');
        $subject = sprintf(
            /* translators: %s: website name */
            __('Order from %s', 'wpkj-fluentcart-alipay-payment'),
            $siteName
        );
        
        // Same truncation logic
        if (strlen($subject) > AlipayConfig::MAX_SUBJECT_LENGTH) {
            $subject = mb_substr($subject, 0, floor(AlipayConfig::MAX_SUBJECT_LENGTH / 3));
            while (strlen($subject) > AlipayConfig::MAX_SUBJECT_LENGTH - 3) {
                $subject = mb_substr($subject, 0, mb_strlen($subject, 'UTF-8') - 1, 'UTF-8');
            }
            $subject .= '...';
        }
        
        return $subject;
    }

    /**
     * Build payment body
     * 
     * @param Order $order Order instance
     * @return string Body
     */
    private function buildBody($order)
    {
        $items = [];
        foreach ($order->order_items as $item) {
            $itemTitle = !empty($item->title) ? $item->title : $item->post_title;
            $items[] = $itemTitle . ' x' . $item->quantity;
        }

        $body = implode(', ', $items);
        
        // EDD plugin method: check byte length with strlen()
        // Truncate safely using mb_substr() to avoid breaking UTF-8
        if (strlen($body) > AlipayConfig::MAX_BODY_LENGTH) {
            $body = mb_substr($body, 0, floor(AlipayConfig::MAX_BODY_LENGTH / 3));
            while (strlen($body) > AlipayConfig::MAX_BODY_LENGTH - 3) {
                $body = mb_substr($body, 0, mb_strlen($body, 'UTF-8') - 1, 'UTF-8');
            }
            $body .= '...';
        }
        
        return $body;
    }

    /**
     * Get return URL
     * 
     * @param OrderTransaction $transaction Transaction instance
     * @return string Return URL
     */
    private function getReturnUrl($transaction)
    {
        // CRITICAL FIX: Do NOT include 'method' parameter!
        // Alipay will add its own method=alipay.trade.page.pay.return
        // Having two 'method' parameters causes "suspected-attack" error
        
        $order = $transaction->order;
        if (!$order) {
            // Fallback if order not found
            return home_url('/?fluent-cart=receipt&trx_hash=' . $transaction->uuid);
        }
        
        // Use Transaction's getReceiptPageUrl() method (FluentCart standard)
        // This method uses StoreSettings and does NOT include download parameter
        // The true parameter enables filters for third-party extensions
        $receiptUrl = $transaction->getReceiptPageUrl(true);
        
        // Add order hash and redirect flag for additional tracking
        return add_query_arg([
            'order_hash' => $order->uuid,
            'fct_redirect' => 'yes'
        ], $receiptUrl);
    }

    /**
     * Get notify URL
     * 
     * @return string Notify URL
     */
    private function getNotifyUrl()
    {
        // CRITICAL: Do NOT include 'method' parameter in notify_url!
        // FluentCart uses fct_payment_listener to route IPN requests
        // The 'method' will be extracted from POST data, not URL
        
        $baseUrl = trailingslashit(site_url());
        $params = http_build_query([
            'fct_payment_listener' => '1'
        ], '', '&', PHP_QUERY_RFC3986);
        
        return $baseUrl . '?' . $params;
    }

    /**
     * Confirm payment success
     * 
     * @param OrderTransaction $transaction Transaction instance
     * @param array $alipayData Alipay notification data
     * @return void
     */
    public function confirmPaymentSuccess(OrderTransaction $transaction, $alipayData)
    {
        // Check if already processed
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            Logger::info('Payment Already Processed', [
                'transaction_uuid' => $transaction->uuid
            ]);
            return;
        }

        $order = Order::query()->where('id', $transaction->order_id)->first();

        if (!$order) {
            Logger::error('Order Not Found', [
                'transaction_id' => $transaction->id,
                'order_id' => $transaction->order_id
            ]);
            return;
        }

        // Verify payment amount
        $totalAmount = Helper::toCents($alipayData['total_amount']);
        
        // Convert both to integers for comparison to avoid type mismatch
        $expectedAmount = (int)$transaction->total;
        $receivedAmount = (int)$totalAmount;
        
        if ($expectedAmount !== $receivedAmount) {
            Logger::error('Amount mismatch', [
                'expected' => $expectedAmount,
                'received' => $receivedAmount,
                'transaction_uuid' => $transaction->uuid
            ]);
            return;
        }

        // Update transaction
        $transactionUpdateData = [
            'vendor_charge_id' => $alipayData['trade_no'],
            'payment_method' => 'alipay',
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total' => $totalAmount,
            'payment_method_type' => 'Alipay',
            'meta' => array_merge($transaction->meta ?? [], [
                'alipay_trade_no' => $alipayData['trade_no'],
                'buyer_logon_id' => $alipayData['buyer_logon_id'] ?? '',
                'buyer_user_id' => $alipayData['buyer_user_id'] ?? '',
                'payment_time' => $alipayData['gmt_payment'] ?? '',
                'trade_status' => $alipayData['trade_status'] ?? '',
            ])
        ];

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        Logger::info('Payment confirmed', [
            'transaction_uuid' => $transaction->uuid,
            'trade_no' => $alipayData['trade_no'],
            'amount' => $totalAmount
        ]);

        // Add log to order activity
        fluent_cart_add_log(
            __('Alipay Payment Confirmation', 'wpkj-fluentcart-alipay-payment'),
            sprintf(
                /* translators: %s: Alipay transaction ID */
                __('Payment confirmed from Alipay. Trade No: %s', 'wpkj-fluentcart-alipay-payment'),
                $alipayData['trade_no']
            ),
            'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id,
            ]
        );

        // Sync order statuses
        (new StatusHelper($order))->syncOrderStatuses($transaction);
        
        // CRITICAL FIX: Clear cart's order_id to allow repeat purchases
        // Using centralized OrderService for DRY principle
        OrderService::clearCartOrderAssociation($order, 'payment_confirmation');
    }

    /**
     * Process failed payment
     * 
     * @param OrderTransaction $transaction Transaction instance
     * @param array $data Failure data
     * @return void
     */
    public function processFailedPayment(OrderTransaction $transaction, $data)
    {
        $order = Order::query()->where('id', $transaction->order_id)->first();

        if (!$order) {
            return;
        }

        Logger::error('Payment Failed', [
            'transaction_uuid' => $transaction->uuid,
            'reason' => $data['reason'] ?? 'Unknown'
        ]);

        // Add log to order activity
        fluent_cart_add_log(
            __('Alipay Payment Failed', 'wpkj-fluentcart-alipay-payment'),
            sprintf(
                /* translators: %s: failure reason */
                __('Payment failed. Reason: %s', 'wpkj-fluentcart-alipay-payment'),
                $data['reason'] ?? 'Unknown'
            ),
            'error',
            [
                'module_name' => 'order',
                'module_id' => $order->id,
            ]
        );
    }
}
