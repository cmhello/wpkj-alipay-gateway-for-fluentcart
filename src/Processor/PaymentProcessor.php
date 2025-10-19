<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\PaymentHelper;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
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
            // Validate transaction status - prevent duplicate payment
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                throw new \Exception(
                    __('Transaction has already been completed.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            // Validate order status
            if (in_array($order->status, ['completed', 'processing'])) {
                Logger::warning('Payment Attempt on Completed Order', [
                    'order_uuid' => $order->uuid,
                    'order_status' => $order->status,
                    'transaction_status' => $transaction->status
                ]);
            }

            // Build payment data
            $paymentData = $this->buildPaymentData($paymentInstance);

            // Create payment via API
            $result = $this->api->createPayment($paymentData);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            Logger::info('Payment Initiated', [
                'order_uuid' => $order->uuid,
                'transaction_uuid' => $transaction->uuid,
                'amount' => $paymentData['total_amount']
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

        // Check Alipay single transaction limit (500,000 CNY)
        if ($totalAmount > 500000) {
            throw new \Exception(
                __('Payment amount exceeds Alipay single transaction limit (500,000 CNY).', 'wpkj-fluentcart-alipay-payment')
            );
        }

        // Generate out_trade_no
        $outTradeNo = Helper::generateOutTradeNo($transaction->uuid);

        // Build subject and body
        $subject = $this->buildSubject($order);
        $body = $this->buildBody($order);

        // Get URLs (build manually to avoid HTML encoding)
        $returnUrl = $this->getReturnUrl($transaction->uuid);
        $notifyUrl = $this->getNotifyUrl();

        return [
            'out_trade_no' => $outTradeNo,
            'total_amount' => $totalAmount,
            'subject' => $subject,
            'body' => $body,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'timeout_express' => '30m', // 30 minutes timeout
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
            return mb_substr($item->post_title . ' ' . $item->title, 0, 256);
        }

        $siteName = get_bloginfo('name');
        return sprintf(__('Order from %s', 'wpkj-fluentcart-alipay-payment'), $siteName);
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
            $items[] = $item->post_title . ' x' . $item->quantity;
        }

        return mb_substr(implode(', ', $items), 0, 400);
    }

    /**
     * Get return URL
     * 
     * @param string $transactionUuid Transaction UUID
     * @return string Return URL
     */
    private function getReturnUrl($transactionUuid)
    {
        // CRITICAL FIX: Do NOT include 'method' parameter!
        // Alipay will add its own method=alipay.trade.page.pay.return
        // Having two 'method' parameters causes "suspected-attack" error
        
        $storeSettings = new \FluentCart\Api\StoreSettings();
        $receiptPage = $storeSettings->getReceiptPage();
        
        // If receipt page not configured, use FluentCart routing
        if (empty($receiptPage)) {
            $receiptPage = home_url('/?fluent-cart=receipt');
        }
        
        // Only use unique identifiers that won't conflict with Alipay's parameters
        return add_query_arg([
            'trx_hash' => $transactionUuid,
            'fct_redirect' => 'yes'
        ], $receiptPage);
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
            Logger::error('Amount Mismatch', [
                'expected' => $expectedAmount,
                'received' => $receivedAmount,
                'expected_type' => gettype($transaction->total),
                'received_type' => gettype($totalAmount),
                'difference' => abs($expectedAmount - $receivedAmount),
                'transaction_uuid' => $transaction->uuid,
                'original_expected' => $transaction->total,
                'original_received' => $totalAmount
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

        Logger::info('Payment Confirmed', [
            'transaction_uuid' => $transaction->uuid,
            'trade_no' => $alipayData['trade_no'],
            'amount' => $totalAmount
        ]);

        // Add log to order activity
        fluent_cart_add_log(
            __('Alipay Payment Confirmation', 'wpkj-fluentcart-alipay-payment'),
            sprintf(
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
