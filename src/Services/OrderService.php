<?php

namespace WPKJFluentCart\Alipay\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Order Service
 * 
 * Centralized service for order-related operations
 * This follows the DRY (Don't Repeat Yourself) principle by providing
 * reusable methods for common order operations across the plugin.
 */
class OrderService
{
    /**
     * Clear cart's order_id association after successful payment
     * 
     * This is a critical operation that allows users to make repeat purchases
     * of the same product. Without this, FluentCart would block repeat orders
     * with "already completed" error because it reuses carts based on cart_hash
     * (which is same for same product).
     * 
     * Design principles:
     * - Single responsibility: Only handles cart-order disassociation
     * - Fail-safe: Errors are logged but don't throw exceptions
     * - Idempotent: Can be called multiple times safely
     * 
     * @param Order $order Completed order
     * @param string $context Context of the call (for logging)
     * @return bool True if successful, false otherwise
     */
    public static function clearCartOrderAssociation(Order $order, string $context = 'payment_confirmation'): bool
    {
        try {
            $cart = Cart::query()
                ->where('order_id', $order->id)
                ->first();
            
            if (!$cart) {
                Logger::info('Cart Not Found for Order', [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'context' => $context
                ]);
                return false;
            }
            
            // Store original values for logging
            $originalOrderId = $cart->order_id;
            $originalStage = $cart->stage;
            
            // Clear the order association
            $cart->order_id = null;
            
            // Mark cart as completed to prevent reuse
            // This is critical: FluentCart skips carts with stage='completed'
            // when looking for existing carts to reuse
            $cart->stage = 'completed';
            
            $saved = $cart->save();
            
            if ($saved) {
                Logger::info('Cart Order Association Cleared', [
                    'cart_id' => $cart->id,
                    'cart_hash' => $cart->cart_hash,
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'context' => $context,
                    'changes' => [
                        'order_id' => ['from' => $originalOrderId, 'to' => null],
                        'stage' => ['from' => $originalStage, 'to' => 'completed']
                    ]
                ]);
                return true;
            }
            
            Logger::error('Failed to Save Cart Changes', [
                'cart_id' => $cart->id,
                'order_id' => $order->id,
                'context' => $context
            ]);
            return false;
            
        } catch (\Exception $e) {
            // Log error but don't throw exception
            // Payment success should not be affected by cart cleanup issues
            Logger::error('Exception in clearCartOrderAssociation', [
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Get order by UUID
     * 
     * Convenience method with error handling and logging
     * 
     * @param string $uuid Order UUID
     * @return Order|null
     */
    public static function getOrderByUuid(string $uuid): ?Order
    {
        try {
            // Validate UUID format before query
            if (!self::isValidUuid($uuid)) {
                Logger::warning('Invalid UUID Format', ['uuid' => $uuid]);
                return null;
            }
            
            $order = Order::query()->where('uuid', $uuid)->first();
            
            if (!$order) {
                Logger::warning('Order Not Found by UUID', [
                    'uuid' => $uuid
                ]);
            }
            
            return $order;
            
        } catch (\Exception $e) {
            Logger::error('Error Getting Order by UUID', [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Validate UUID format (RFC 4122)
     * 
     * @param string $uuid UUID to validate
     * @return bool
     */
    private static function isValidUuid(string $uuid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        return (bool)preg_match($pattern, $uuid);
    }
    
    /**
     * Check if order is already completed
     * 
     * @param Order $order Order instance
     * @return bool
     */
    public static function isOrderCompleted(Order $order): bool
    {
        $successStatuses = [
            'completed',
            'processing'
        ];
        
        return in_array($order->status, $successStatuses) ||
               $order->payment_status === 'paid';
    }
    
    /**
     * Check if order can be paid
     * 
     * Validates order state before attempting payment
     * 
     * @param Order $order Order instance
     * @return array ['can_pay' => bool, 'reason' => string]
     */
    public static function canOrderBePaid(Order $order): array
    {
        // Already completed
        if (self::isOrderCompleted($order)) {
            return [
                'can_pay' => false,
                'reason' => 'Order already completed'
            ];
        }
        
        // Cancelled
        if ($order->status === 'cancelled') {
            return [
                'can_pay' => false,
                'reason' => 'Order is cancelled'
            ];
        }
        
        // Refunded
        if ($order->payment_status === 'refunded') {
            return [
                'can_pay' => false,
                'reason' => 'Order is refunded'
            ];
        }
        
        // No amount due
        $totalDue = $order->total_amount - $order->total_paid;
        if ($totalDue <= 0) {
            return [
                'can_pay' => false,
                'reason' => 'No amount due'
            ];
        }
        
        return [
            'can_pay' => true,
            'reason' => ''
        ];
    }
}
