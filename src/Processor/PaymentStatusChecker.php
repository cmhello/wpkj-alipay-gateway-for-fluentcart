<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Services\OrderService;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Payment Status Checker
 * 
 * Handles AJAX requests to check face-to-face payment status
 */
class PaymentStatusChecker
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
     */
    public function __construct()
    {
        $this->settings = new AlipaySettingsBase();
        $this->api = new AlipayAPI($this->settings);
    }

    /**
     * Register AJAX hooks
     * 
     * @return void
     */
    public function register()
    {
        add_action('wp_ajax_wpkj_alipay_check_payment_status', [$this, 'checkPaymentStatus']);
        add_action('wp_ajax_nopriv_wpkj_alipay_check_payment_status', [$this, 'checkPaymentStatus']);
    }

    /**
     * Check payment status via AJAX
     * 
     * @return void
     */
    public function checkPaymentStatus()
    {
        try {
            // Verify nonce for CSRF protection
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Nonce verification handles security
            if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'wpkj_alipay_check_status')) {
                wp_send_json_error([
                    'message' => __('Security verification failed', 'wpkj-fluentcart-alipay-payment')
                ], 403);
                return;
            }
            
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_text_field handles this
            $transactionUuid = isset($_POST['transaction_uuid']) ? sanitize_text_field(wp_unslash($_POST['transaction_uuid'])) : '';

            if (empty($transactionUuid)) {
                wp_send_json_error([
                    'message' => __('Invalid transaction ID', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionUuid)
                ->first();

            if (!$transaction) {
                wp_send_json_error([
                    'message' => __('Transaction not found', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            // Check if payment is already completed
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                $order = Order::find($transaction->order_id);
                
                // Use Transaction's getReceiptPageUrl() method (FluentCart standard)
                $receiptUrl = $transaction->getReceiptPageUrl(true);
                
                // Add order hash for additional tracking
                $receiptUrl = add_query_arg([
                    'order_hash' => $order->uuid
                ], $receiptUrl);
                
                wp_send_json_success([
                    'status' => 'paid',
                    'message' => __('Payment completed successfully', 'wpkj-fluentcart-alipay-payment'),
                    'redirect_url' => $receiptUrl
                ]);
                return;
            }

            // CRITICAL: Retrieve out_trade_no from transaction meta
            // DO NOT regenerate it because it contains creation timestamp
            $outTradeNo = $transaction->meta['out_trade_no'] ?? null;
            
            // Fallback: If out_trade_no not in meta (old transactions), try to generate
            // But this won't work for new timestamp-based out_trade_no format
            if (empty($outTradeNo)) {
                Logger::warning('Missing out_trade_no in Transaction Meta', [
                    'transaction_uuid' => $transaction->uuid,
                    'transaction_id' => $transaction->id
                ]);
                
                // For backward compatibility, generate without timestamp
                $outTradeNo = str_replace('-', '', $transaction->uuid);
            }
            
            $result = $this->api->queryTrade($outTradeNo);

            if (is_wp_error($result)) {
                wp_send_json_success([
                    'status' => 'waiting',
                    'message' => __('Waiting for payment', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            if (isset($result['trade_status'])) {
                $tradeStatus = $result['trade_status'];

                if ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED') {
                    // Process payment confirmation immediately
                    $confirmed = $this->processPaymentConfirmation($transaction, $result);
                    
                    if (!$confirmed) {
                        // Confirmation failed (amount mismatch, etc.), continue waiting
                        wp_send_json_success([
                            'status' => 'waiting',
                            'message' => __('Payment verification in progress...', 'wpkj-fluentcart-alipay-payment')
                        ]);
                        return;
                    }
                    
                    // Reload transaction and order to get updated data
                    $transaction = $transaction->fresh();
                    $order = $transaction->order;
                    
                    Logger::info('Face-to-Face Payment Confirmed via Status Check', [
                        'transaction_uuid' => $transaction->uuid,
                        'order_id' => $order->id,
                        'trade_status' => $tradeStatus
                    ]);
                    
                    // Use Transaction's getReceiptPageUrl() method (FluentCart standard)
                    $receiptUrl = $transaction->getReceiptPageUrl(true);
                    
                    // Add order hash for additional tracking
                    $receiptUrl = add_query_arg([
                        'order_hash' => $order->uuid
                    ], $receiptUrl);
                    
                    wp_send_json_success([
                        'status' => 'paid',
                        'message' => __('Payment completed successfully', 'wpkj-fluentcart-alipay-payment'),
                        'redirect_url' => $receiptUrl
                    ]);
                    return;
                } elseif ($tradeStatus === 'TRADE_CLOSED') {
                    wp_send_json_success([
                        'status' => 'failed',
                        'message' => __('Payment cancelled or closed', 'wpkj-fluentcart-alipay-payment')
                    ]);
                    return;
                }
            }

            wp_send_json_success([
                'status' => 'waiting',
                'message' => __('Waiting for payment', 'wpkj-fluentcart-alipay-payment')
            ]);

        } catch (\Exception $e) {
            Logger::error('Payment Status Check Error', [
                'message' => $e->getMessage(),
                'transaction_uuid' => $transactionUuid ?? ''
            ]);

            wp_send_json_error([
                'message' => __('Failed to check payment status', 'wpkj-fluentcart-alipay-payment')
            ]);
        }
    }

    /**
     * Process payment confirmation
     * 
     * @param OrderTransaction $transaction Transaction instance
     * @param array $tradeData Trade data from Alipay
     * @return bool True if confirmation succeeded, false otherwise
     */
    private function processPaymentConfirmation($transaction, $tradeData)
    {
        // Check if already processed
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            Logger::info('Transaction Already Completed', [
                'transaction_uuid' => $transaction->uuid
            ]);
            return true; // Already processed, consider it success
        }

        $order = Order::find($transaction->order_id);
        if (!$order) {
            Logger::error('Order Not Found for Payment Confirmation', [
                'transaction_id' => $transaction->id,
                'order_id' => $transaction->order_id
            ]);
            return false;
        }

        // Verify amount if available
        if (isset($tradeData['total_amount'])) {
            $totalAmount = Helper::toCents($tradeData['total_amount']);
            $expectedAmount = (int)$transaction->total;
            $receivedAmount = (int)$totalAmount;
            
            if ($expectedAmount !== $receivedAmount) {
                Logger::error('Amount Mismatch in Status Check', [
                    'expected' => $expectedAmount,
                    'received' => $receivedAmount,
                    'transaction_uuid' => $transaction->uuid,
                    'trade_no' => $tradeData['trade_no'] ?? 'N/A'
                ]);
                return false; // Amount mismatch, reject confirmation
            }
        }

        // Update transaction
        $transactionUpdateData = [
            'vendor_charge_id' => $tradeData['trade_no'] ?? '',
            'payment_method' => 'alipay',
            'status' => Status::TRANSACTION_SUCCEEDED,
            'payment_method_type' => 'Alipay Face-to-Face',
            'meta' => array_merge($transaction->meta ?? [], [
                'alipay_trade_no' => $tradeData['trade_no'] ?? '',
                'buyer_logon_id' => $tradeData['buyer_logon_id'] ?? '',
                'buyer_user_id' => $tradeData['buyer_user_id'] ?? '',
                'payment_time' => $tradeData['send_pay_date'] ?? '',
                'trade_status' => $tradeData['trade_status'] ?? '',
                'confirmed_via' => 'status_polling'
            ])
        ];

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        Logger::info('Payment Confirmed via Polling', [
            'transaction_uuid' => $transaction->uuid,
            'trade_no' => $tradeData['trade_no'] ?? 'N/A',
            'trade_status' => $tradeData['trade_status'] ?? 'N/A'
        ]);

        // Add log to order activity
        fluent_cart_add_log(
            __('Alipay Face-to-Face Payment Confirmed', 'wpkj-fluentcart-alipay-payment'),
            sprintf(
                /* translators: %s: Alipay transaction ID */
                __('Payment confirmed via status polling. Trade No: %s', 'wpkj-fluentcart-alipay-payment'),
                $tradeData['trade_no'] ?? 'N/A'
            ),
            'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id,
            ]
        );

        // Sync order statuses
        (new \FluentCart\App\Helpers\StatusHelper($order))->syncOrderStatuses($transaction);
        
        // CRITICAL FIX: Clear cart's order_id to allow repeat purchases
        // Using centralized OrderService for DRY principle
        OrderService::clearCartOrderAssociation($order, 'status_polling');
        
        // CRITICAL: Handle subscription status update
        if ($this->isSubscriptionTransaction($transaction)) {
            Logger::info('Processing Subscription Payment via Polling', [
                'transaction_uuid' => $transaction->uuid,
                'trade_no' => $tradeData['trade_no'] ?? ''
            ]);
            
            $this->handleSubscriptionPaymentSuccess($transaction, $tradeData);
        }
        
        return true; // Confirmation succeeded
    }

    /**
     * Check if transaction is for a subscription
     * 
     * @param OrderTransaction $transaction
     * @return bool
     */
    private function isSubscriptionTransaction($transaction)
    {
        // Check transaction meta
        if (isset($transaction->meta['is_subscription']) && $transaction->meta['is_subscription']) {
            return true;
        }

        // Check if order has subscription
        $order = $transaction->order;
        if ($order && $order->subscription_id) {
            return true;
        }

        return false;
    }

    /**
     * Handle subscription payment success
     * 
     * @param OrderTransaction $transaction
     * @param array $data Payment data
     * @return void
     */
    private function handleSubscriptionPaymentSuccess($transaction, $data)
    {
        // Get subscription
        $subscriptionId = $transaction->meta['subscription_id'] ?? $transaction->order->subscription_id ?? null;
        
        if (!$subscriptionId) {
            Logger::warning('Subscription ID Not Found in Transaction', [
                'transaction_uuid' => $transaction->uuid
            ]);
            return;
        }

        $subscription = Subscription::find($subscriptionId);
        
        if (!$subscription) {
            Logger::error('Subscription Not Found', [
                'subscription_id' => $subscriptionId,
                'transaction_uuid' => $transaction->uuid
            ]);
            return;
        }

        // Update subscription status to active
        if ($subscription->status !== Status::SUBSCRIPTION_ACTIVE) {
            $subscription->status = Status::SUBSCRIPTION_ACTIVE;
            $subscription->save();

            Logger::info('Subscription Activated via Polling', [
                'subscription_id' => $subscription->id,
                'transaction_uuid' => $transaction->uuid,
                'previous_status' => $subscription->getOriginal('status')
            ]);
        }

        // Increment bill count for renewals
        $order = $transaction->order;
        if ($order && $order->type === 'renewal') {
            $subscription->bill_count = ($subscription->bill_count ?? 0) + 1;
            
            // Calculate next billing date using WordPress timezone-safe function
            $interval = $subscription->billing_interval;
            $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime("+1 {$interval}", current_time('timestamp')));
            
            // Check if subscription should complete (limited billing cycles)
            if ($subscription->bill_times > 0 && $subscription->bill_count >= $subscription->bill_times) {
                $subscription->status = Status::SUBSCRIPTION_COMPLETED;
                $subscription->next_billing_date = null;
                
                Logger::info('Subscription Completed via Polling (Max Billing Cycles Reached)', [
                    'subscription_id' => $subscription->id,
                    'bill_count' => $subscription->bill_count,
                    'bill_times' => $subscription->bill_times
                ]);
            } else {
                $subscription->next_billing_date = $nextBillingDate;
                
                Logger::info('Next Billing Date Updated via Polling', [
                    'subscription_id' => $subscription->id,
                    'next_billing_date' => $nextBillingDate,
                    'bill_count' => $subscription->bill_count
                ]);
            }
            
            $subscription->save();
        }
    }
}
