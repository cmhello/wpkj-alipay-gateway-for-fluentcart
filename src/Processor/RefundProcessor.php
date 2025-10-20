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

        $this->processAutoRefund($order);
    }

    /**
     * Process automatic refund for cancelled order
     * 
     * @param Order $order Order instance
     * @return void
     */
    private function processAutoRefund($order)
    {
        if (!$this->isOrderEligibleForRefund($order)) {
            return;
        }

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

        $existingRefund = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->first();

        if ($existingRefund) {
            $refundMeta = $existingRefund->meta ?? [];
            if (isset($refundMeta['refund_type']) && 
                $refundMeta['refund_type'] === 'automatic' &&
                isset($refundMeta['original_transaction_uuid']) &&
                $refundMeta['original_transaction_uuid'] === $transaction->uuid) {
                return;
            }
        }

        if ($order->total_refund >= $order->total_paid) {
            return;
        }

        $refundAmount = $order->total_paid - $order->total_refund;

        if ($refundAmount <= 0) {
            return;
        }

        $this->executeRefund($order, $transaction, $refundAmount);
    }

    /**
     * Execute refund through Alipay API
     * 
     * @param Order $order Order instance
     * @param OrderTransaction $transaction Transaction instance
     * @param int $refundAmount Refund amount in cents
     * @return void
     */
    private function executeRefund($order, $transaction, $refundAmount)
    {
        try {
            // CRITICAL: Retrieve out_trade_no from transaction meta
            // DO NOT regenerate because it contains creation timestamp
            $outTradeNo = $transaction->meta['out_trade_no'] ?? null;
            
            // Fallback for old transactions without stored out_trade_no
            if (empty($outTradeNo)) {
                // Try using vendor_charge_id (trade_no from Alipay)
                if (!empty($transaction->vendor_charge_id)) {
                    Logger::info('Using vendor_charge_id for refund (trade_no)', [
                        'order_id' => $order->id,
                        'trade_no' => $transaction->vendor_charge_id
                    ]);
                    // For refund, we'll use trade_no in the refund call
                    $outTradeNo = null; // Will use trade_no parameter instead
                } else {
                    // Last resort: use old format (without timestamp)
                    Logger::warning('Missing out_trade_no and trade_no, using fallback', [
                        'transaction_uuid' => $transaction->uuid
                    ]);
                    $outTradeNo = str_replace('-', '', $transaction->uuid);
                }
            }
            
            $refundAmountDecimal = Helper::toDecimal($refundAmount);
            $outRequestNo = $transaction->uuid . '-auto-' . time() . '-' . substr(md5(uniqid()), 0, 8);

            $refundParams = [
                'refund_amount' => $refundAmountDecimal,
                'out_request_no' => $outRequestNo,
                'refund_reason' => 'Order cancelled - automatic refund'
            ];
            
            // Prefer trade_no over out_trade_no if available
            if (!empty($transaction->vendor_charge_id)) {
                $refundParams['trade_no'] = $transaction->vendor_charge_id;
            } elseif (!empty($outTradeNo)) {
                $refundParams['out_trade_no'] = $outTradeNo;
            } else {
                throw new \Exception('Cannot process refund: missing both trade_no and out_trade_no');
            }
            
            $result = $this->api->refund($refundParams);

            if (is_wp_error($result)) {
                $this->handleRefundError($order, $transaction, $result);
                return;
            }

            $responseKey = 'alipay_trade_refund_response';
            if (!isset($result[$responseKey])) {
                Logger::error('Invalid refund response structure', ['order_id' => $order->id]);
                $this->addOrderLog($order, 'Auto-refund Failed', 'Invalid response from Alipay');
                return;
            }

            $refundResponse = $result[$responseKey];

            if (!isset($refundResponse['code']) || $refundResponse['code'] !== '10000') {
                $errorMsg = $refundResponse['sub_msg'] ?? $refundResponse['msg'] ?? 'Unknown error';
                Logger::error('Alipay refund failed', [
                    'order_id' => $order->id,
                    'code' => $refundResponse['code'] ?? 'unknown',
                    'message' => $errorMsg
                ]);
                $this->addOrderLog($order, 'Auto-refund Failed', 
                    sprintf('Alipay error: %s', $errorMsg));
                return;
            }

            if (isset($refundResponse['fund_change']) && $refundResponse['fund_change'] === 'N') {
                Logger::warning('Refund succeeded but no fund change', ['order_id' => $order->id]);
                $this->addOrderLog($order, 'Auto-refund Warning',
                    'Refund processed but no fund change detected. Please verify manually.');
            }

            $this->createRefundTransaction($order, $transaction, $refundAmount, $refundResponse);

        } catch (\Exception $e) {
            Logger::error('Auto-refund exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            $this->addOrderLog($order, 'Auto-refund Exception',
                sprintf('Error: %s', $e->getMessage()));
        }
    }

    /**
     * Create refund transaction record
     * 
     * @param Order $order Order instance
     * @param OrderTransaction $originalTransaction Original payment transaction
     * @param int $refundAmount Refund amount in cents
     * @param array $refundResponse Alipay refund response
     * @return void
     */
    private function createRefundTransaction($order, $originalTransaction, $refundAmount, $refundResponse)
    {
        try {
            $refundTransaction = new OrderTransaction();
            $refundTransaction->order_id = $order->id;
            $refundTransaction->transaction_type = Status::TRANSACTION_TYPE_REFUND;
            $refundTransaction->payment_method = 'alipay';
            $refundTransaction->payment_method_type = 'Alipay';
            $refundTransaction->status = Status::TRANSACTION_REFUNDED;
            $refundTransaction->total = $refundAmount;
            $refundTransaction->currency = $order->currency;
            $refundTransaction->vendor_charge_id = $refundResponse['trade_no'] ?? '';
            $refundTransaction->uuid = md5(uniqid('refund_', true));
            $refundTransaction->meta = [
                'refund_type' => 'automatic',
                'refund_reason' => 'Order cancelled - automatic refund',
                'original_transaction_id' => $originalTransaction->id,
                'original_transaction_uuid' => $originalTransaction->uuid,
                'alipay_trade_no' => $refundResponse['trade_no'] ?? '',
                'refund_fee' => $refundResponse['refund_fee'] ?? '',
                'fund_change' => $refundResponse['fund_change'] ?? '',
                'gmt_refund_pay' => $refundResponse['gmt_refund_pay'] ?? '',
                'buyer_logon_id' => $refundResponse['buyer_logon_id'] ?? '',
                'auto_refund_triggered_at' => current_time('mysql')
            ];
            
            if (!$refundTransaction->save()) {
                throw new \Exception('Failed to save refund transaction');
            }

            $order->total_refund = ($order->total_refund ?? 0) + $refundAmount;
            
            if ($order->total_refund >= $order->total_paid) {
                $order->payment_status = Status::PAYMENT_REFUNDED;
                $order->refunded_at = current_time('mysql');
            } else {
                $order->payment_status = Status::PAYMENT_PARTIALLY_REFUNDED;
            }
            
            if (!$order->save()) {
                throw new \Exception('Failed to update order payment status');
            }

            $this->addOrderLog($order, 'Auto-refund Successful',
                sprintf('Refund of %s processed automatically. Trade No: %s',
                    Helper::formatAmount($refundAmount, $order->currency),
                    $refundResponse['trade_no'] ?? 'N/A'
                )
            );

            do_action('fluent_cart/order_refund_completed', [
                'order' => $order,
                'transaction' => $refundTransaction,
                'refund_amount' => $refundAmount,
                'refund_type' => 'automatic'
            ]);
            
        } catch (\Exception $e) {
            Logger::error('Failed to create refund transaction', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            $this->addOrderLog($order, 'Auto-refund Failed',
                sprintf('Database error: %s', $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Handle refund error
     * 
     * @param Order $order Order instance
     * @param OrderTransaction $transaction Transaction instance
     * @param \WP_Error $error Error object
     * @return void
     */
    private function handleRefundError($order, $transaction, $error)
    {
        Logger::error('Auto-refund API error', [
            'order_id' => $order->id,
            'transaction_uuid' => $transaction->uuid,
            'error_code' => $error->get_error_code(),
            'error_message' => $error->get_error_message()
        ]);

        $this->addOrderLog($order,
            'Auto-refund Failed',
            sprintf(
                'Failed to process refund: %s (Error: %s)',
                $error->get_error_message(),
                $error->get_error_code()
            )
        );
    }

    /**
     * Check if order is eligible for refund
     * 
     * @param Order $order Order instance
     * @return bool
     */
    private function isOrderEligibleForRefund($order)
    {
        if (!in_array($order->payment_status, [Status::PAYMENT_PAID, Status::PAYMENT_PARTIALLY_REFUNDED])) {
            return false;
        }

        if ($order->total_paid <= 0) {
            return false;
        }

        if ($order->total_refund >= $order->total_paid) {
            return false;
        }

        return true;
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
