<?php

namespace WPKJFluentCart\Alipay\Utils;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;

/**
 * Transaction Helper
 * 
 * Centralized utility for transaction-related operations
 * Provides idempotency protection and common transaction logic
 */
class TransactionHelper
{
    /**
     * Lock timeout in seconds
     * 
     * @var int
     */
    private const LOCK_TIMEOUT = 60;

    /**
     * Get out_trade_no from transaction
     * 
     * Retrieves the out_trade_no from transaction meta with fallback strategies
     * 
     * @param OrderTransaction $transaction Transaction model
     * @return string Out trade number
     */
    public static function getOutTradeNo(OrderTransaction $transaction)
    {
        // Priority 1: From transaction meta (recommended)
        $outTradeNo = Arr::get($transaction->meta, 'out_trade_no');
        
        if ($outTradeNo) {
            return $outTradeNo;
        }

        // Priority 2: From order meta
        if ($transaction->order) {
            $outTradeNo = $transaction->order->getMeta('alipay_out_trade_no');
            if ($outTradeNo) {
                return $outTradeNo;
            }
        }

        // Priority 3: Fallback to UUID-based format (legacy)
        return str_replace('-', '', $transaction->uuid);
    }

    /**
     * Store out_trade_no in transaction meta
     * 
     * @param OrderTransaction $transaction Transaction model
     * @param string $outTradeNo Out trade number
     * @return void
     */
    public static function storeOutTradeNo(OrderTransaction $transaction, $outTradeNo)
    {
        $meta = $transaction->meta ?? [];
        $meta['out_trade_no'] = $outTradeNo;
        
        $transaction->meta = $meta;
        $transaction->save();

        Logger::debug('Out Trade Number Stored', [
            'transaction_id' => $transaction->id,
            'out_trade_no' => $outTradeNo
        ]);
    }

    /**
     * Check if transaction is in completed state
     * 
     * @param OrderTransaction $transaction Transaction model
     * @return bool True if completed
     */
    public static function isCompleted(OrderTransaction $transaction)
    {
        $completedStatuses = [
            'succeeded',
            'completed',
            'refunded',
            'partially_refunded'
        ];

        return in_array($transaction->status, $completedStatuses);
    }

    /**
     * Check if transaction is pending
     * 
     * @param OrderTransaction $transaction Transaction model
     * @return bool True if pending
     */
    public static function isPending(OrderTransaction $transaction)
    {
        $pendingStatuses = [
            'pending',
            'processing',
            'intended'
        ];

        return in_array($transaction->status, $pendingStatuses);
    }

    /**
     * Ensure operation idempotency using distributed lock
     * 
     * Prevents duplicate operations on the same transaction
     * 
     * @param string $operation Operation name (e.g., 'payment', 'refund')
     * @param string $uniqueKey Unique identifier (e.g., transaction UUID)
     * @param callable $callback Operation to execute
     * @return mixed Operation result
     * @throws \Exception If lock cannot be acquired
     */
    public static function withIdempotencyLock($operation, $uniqueKey, callable $callback)
    {
        $lockKey = "alipay_lock_{$operation}_{$uniqueKey}";
        
        // Check if operation is already in progress
        if (get_transient($lockKey)) {
            Logger::warning('Operation Already In Progress (Idempotency Lock)', [
                'operation' => $operation,
                'unique_key' => $uniqueKey
            ]);
            
            throw new \Exception(
                sprintf(
                    /* translators: %s: operation name */
                    esc_html__('Operation "%s" is already in progress. Please wait.', 'wpkj-fluentcart-alipay-payment'),
                    esc_html($operation)
                )
            );
        }

        // Acquire lock
        set_transient($lockKey, true, self::LOCK_TIMEOUT);
        
        Logger::debug('Idempotency Lock Acquired', [
            'operation' => $operation,
            'unique_key' => $uniqueKey,
            'timeout' => self::LOCK_TIMEOUT
        ]);

        try {
            // Execute operation
            $result = $callback();
            
            // Release lock on success
            delete_transient($lockKey);
            
            Logger::debug('Idempotency Lock Released', [
                'operation' => $operation,
                'unique_key' => $uniqueKey
            ]);
            
            return $result;

        } catch (\Exception $e) {
            // Release lock on error
            delete_transient($lockKey);
            
            Logger::error('Operation Failed, Lock Released', [
                'operation' => $operation,
                'unique_key' => $uniqueKey,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Check if duplicate transaction exists
     * 
     * @param string $outTradeNo Out trade number
     * @return OrderTransaction|null Existing transaction or null
     */
    public static function findByOutTradeNo($outTradeNo)
    {
        // Search by vendor_charge_id first (Alipay trade_no)
        $transaction = OrderTransaction::query()
            ->where('payment_method', 'alipay')
            ->where('vendor_charge_id', '!=', '')
            ->get()
            ->first(function ($txn) use ($outTradeNo) {
                return Arr::get($txn->meta, 'out_trade_no') === $outTradeNo;
            });

        if ($transaction) {
            return $transaction;
        }

        // Search by UUID-based out_trade_no (legacy)
        $uuid = self::outTradeNoToUuid($outTradeNo);
        if ($uuid) {
            return OrderTransaction::query()
                ->where('payment_method', 'alipay')
                ->where('uuid', $uuid)
                ->first();
        }

        return null;
    }

    /**
     * Convert out_trade_no back to UUID format
     * 
     * @param string $outTradeNo Out trade number
     * @return string|null UUID or null
     */
    private static function outTradeNoToUuid($outTradeNo)
    {
        // Legacy format: 32 characters without dashes
        if (strlen($outTradeNo) === 32 && ctype_alnum($outTradeNo)) {
            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($outTradeNo, 0, 8),
                substr($outTradeNo, 8, 4),
                substr($outTradeNo, 12, 4),
                substr($outTradeNo, 16, 4),
                substr($outTradeNo, 20, 12)
            );
        }

        return null;
    }

    /**
     * Validate transaction can be refunded
     * 
     * @param OrderTransaction $transaction Transaction model
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    public static function validateRefundable(OrderTransaction $transaction)
    {
        // Check if transaction has vendor charge ID
        if (!$transaction->vendor_charge_id && !self::getOutTradeNo($transaction)) {
            return new \WP_Error(
                'invalid_transaction',
                __('Transaction does not have valid payment reference.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        // Check if transaction is completed
        if (!self::isCompleted($transaction)) {
            return new \WP_Error(
                'transaction_not_completed',
                __('Transaction is not in completed state.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        // Check if already fully refunded
        if ($transaction->status === 'refunded') {
            return new \WP_Error(
                'already_refunded',
                __('Transaction has already been fully refunded.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        // Check remaining refundable amount
        $refundableAmount = $transaction->total - ($transaction->refunded_total ?? 0);
        if ($refundableAmount <= 0) {
            return new \WP_Error(
                'no_refundable_amount',
                __('No refundable amount remaining.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        return true;
    }

    /**
     * Generate unique refund request number
     * 
     * @param OrderTransaction $transaction Transaction model
     * @param string $suffix Optional suffix (e.g., 'manual', 'auto')
     * @return string Unique refund request number
     */
    public static function generateRefundRequestNo(OrderTransaction $transaction, $suffix = 'manual')
    {
        return sprintf(
            '%s-%s-%s-%s',
            str_replace('-', '', $transaction->uuid),
            $suffix,
            time(),
            substr(md5(uniqid()), 0, 8)
        );
    }
}
