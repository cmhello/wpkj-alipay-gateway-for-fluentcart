<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Refund Processor
 * 
 * Handles automatic refund processing when orders are cancelled
 */
class RefundProcessor
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
     * Register hooks for automatic refund
     * 
     * @return void
     */
    public function register()
    {
        // Hook into order cancellation event (this event is rarely used by FluentCart)
        add_action('fluent_cart/order_canceled', [$this, 'handleOrderCancellation'], 10, 1);
        
        // Hook into order status change to canceled (this is the actual hook that fires)
        // NOTE: FluentCart uses 'canceled' (1 L) not 'cancelled' (2 Ls)
        add_action('fluent_cart/order_status_changed_to_canceled', [$this, 'handleOrderStatusChanged'], 10, 1);
    }

    /**
     * Handle order cancellation event
     * 
     * @param array $data Order cancellation data
     * @return void
     */
    public function handleOrderCancellation($data)
    {
        if (!$this->isAutoRefundEnabled()) {
            return;
        }

        $order = $data['order'] ?? null;
        
        if (!$order) {
            return;
        }

        // Check if this order uses Alipay gateway
        // Only process refunds for Alipay orders
        if ($order->payment_method !== 'alipay') {
            Logger::info('Skipping Alipay refund - different payment method', [
                'order_id' => $order->id,
                'payment_method' => $order->payment_method ?? 'unknown'
            ]);
            return;
        }

        $this->processAutoRefund($order);
    }

    /**
     * Handle order status change to cancelled
     * 
     * @param array $data Order status change data
     * @return void
     */
    public function handleOrderStatusChanged($data)
    {
        if (!$this->isAutoRefundEnabled()) {
            return;
        }

        $order = $data['order'] ?? null;
        
        if (!$order) {
            return;
        }

        // Check if this order uses Alipay gateway
        // Only process refunds for Alipay orders
        if ($order->payment_method !== 'alipay') {
            Logger::info('Skipping Alipay refund - different payment method', [
                'order_id' => $order->id,
                'payment_method' => $order->payment_method ?? 'unknown'
            ]);
            return;
        }

        $this->processAutoRefund($order);
    }

    /**
     * Process automatic refund for cancelled order
     * 
     * Uses FluentCart's standard Refund service for processing refunds.
     * The service handles transaction creation, order updates, and events automatically.
     * 
     * @param Order $order Order instance
     * @return void
     */
    private function processAutoRefund($order)
    {
        // First check: Find the original payment transaction
        $transaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('payment_method', 'alipay')
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$transaction) {
            $this->addOrderLog($order, 
                'Auto-refund Skipped',
                'No successful Alipay payment transaction found for this order.'
            );
            return;
        }

        // CRITICAL SECURITY CHECK: Prevent duplicate refunds
        // Check if this specific transaction has already been refunded automatically
        $existingAutoRefund = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->get();

        if ($existingAutoRefund && $existingAutoRefund->count() > 0) {
            foreach ($existingAutoRefund as $refund) {
                $refundMeta = $refund->meta ?? [];
                
                // Check if this is an automatic refund for the same original transaction
                if (isset($refundMeta['refund_type']) && 
                    $refundMeta['refund_type'] === 'automatic' &&
                    isset($refundMeta['original_transaction_uuid']) &&
                    $refundMeta['original_transaction_uuid'] === $transaction->uuid) {
                    
                    Logger::info('Auto-refund Already Processed - Duplicate Prevention', [
                        'order_id' => $order->id,
                        'original_transaction_uuid' => $transaction->uuid,
                        'existing_refund_id' => $refund->id,
                        'existing_refund_amount' => $refund->total,
                        'refunded_at' => $refundMeta['auto_refund_triggered_at'] ?? 'unknown'
                    ]);
                    
                    $this->addOrderLog($order,
                        'Auto-refund Skipped',
                        sprintf(
                            'Automatic refund already processed for this transaction (Refund ID: %d, Amount: %s)',
                            $refund->id,
                            Helper::formatAmount($refund->total, $order->currency)
                        )
                    );
                    
                    return;
                }
            }
        }

        // Calculate refund amount
        $refundAmount = $order->total_paid - $order->total_refund;

        if ($refundAmount <= 0) {
            Logger::warning('Auto-refund Skipped - Invalid Refund Amount', [
                'order_id' => $order->id,
                'calculated_refund' => $refundAmount,
                'total_paid' => $order->total_paid,
                'total_refund' => $order->total_refund
            ]);
            return;
        }

        // All checks passed - proceed with refund using FluentCart's standard service
        Logger::info('Auto-refund Security Checks Passed - Using FluentCart Refund Service', [
            'order_id' => $order->id,
            'transaction_uuid' => $transaction->uuid,
            'refund_amount' => $refundAmount,
            'total_paid' => $order->total_paid,
            'total_refund' => $order->total_refund
        ]);

        try {
            // Use FluentCart's standard Refund service
            // This will automatically:
            // 1. Validate the refund amount
            // 2. Call AlipayGateway->processRefund() to execute the Alipay API call
            // 3. Create refund transaction record
            // 4. Update order payment status and total_refund
            // 5. Trigger fluent_cart/order_refund_completed event
            // 6. Send refund notification email (if enabled)
            // 7. Restore inventory (if manageStock is enabled)
            $refundService = new \FluentCart\App\Services\Payments\Refund();
            $result = $refundService->processRefund($transaction, $refundAmount, [
                'reason' => 'Order cancelled - automatic refund',
                'manageStock' => false, // Don't restore inventory for cancelled orders
                'refund_type' => 'automatic' // Custom meta to identify automatic refunds
            ]);

            // Check if result is WP_Error
            if (is_wp_error($result)) {
                Logger::error('Auto-refund Failed via FluentCart Service', [
                    'order_id' => $order->id,
                    'error_code' => $result->get_error_code(),
                    'error_message' => $result->get_error_message()
                ]);
                
                $this->addOrderLog($order, 'Auto-refund Failed',
                    sprintf('FluentCart refund service error: %s', $result->get_error_message())
                );
                return;
            }

            if (isset($result['refund_transaction'])) {
                // Update refund transaction meta to mark as automatic
                $refundTransaction = $result['refund_transaction'];
                $meta = $refundTransaction->meta ?? [];
                $meta['refund_type'] = 'automatic';
                $meta['original_transaction_uuid'] = $transaction->uuid;
                $meta['auto_refund_triggered_at'] = current_time('mysql');
                $refundTransaction->meta = $meta;
                $refundTransaction->save();

                Logger::info('Automatic Refund Successful via FluentCart Service', [
                    'order_id' => $order->id,
                    'refund_transaction_id' => $refundTransaction->id,
                    'vendor_refund_id' => $result['vendor_refund_id'] ?? '',
                    'refund_amount' => $refundAmount
                ]);

                $this->addOrderLog($order, 'Auto-refund Successful',
                    sprintf('Refund of %s processed automatically. Alipay Trade No: %s',
                        Helper::formatAmount($refundAmount, $order->currency),
                        $result['vendor_refund_id'] ?? 'N/A'
                    )
                );
            } else {
                // Unexpected result format
                Logger::warning('Auto-refund Unexpected Result Format', [
                    'order_id' => $order->id,
                    'result' => $result
                ]);
                
                $this->addOrderLog($order, 'Auto-refund Warning',
                    'Refund processed but unexpected result format from FluentCart service'
                );
            }

        } catch (\Exception $e) {
            Logger::error('Auto-refund Exception via FluentCart Service', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->addOrderLog($order, 'Auto-refund Exception',
                sprintf('Error: %s', $e->getMessage())
            );
        }
    }



    /**
     * Check if auto-refund is enabled in settings
     * 
     * @return bool
     */
    private function isAutoRefundEnabled()
    {
        $settings = $this->settings->get();
        return isset($settings['auto_refund_on_cancel']) && $settings['auto_refund_on_cancel'] === 'yes';
    }

    /**
     * Add log entry to order activity
     * 
     * @param Order $order Order instance
     * @param string $title Log title
     * @param string $content Log content
     * @return void
     */
    private function addOrderLog($order, $title, $content)
    {
        fluent_cart_add_log(
            $title,
            $content,
            strpos($title, 'Failed') !== false || strpos($title, 'Exception') !== false ? 'error' : 'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id,
            ]
        );
    }
}
