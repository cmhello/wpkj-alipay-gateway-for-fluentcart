<?php

namespace WPKJFluentCart\Alipay\Listeners;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Order Cancel Listener
 * 
 * Handles subscription cancellation when parent order is canceled
 * 
 * This listener ensures that when an order is canceled, any associated
 * subscription is also canceled to maintain data consistency between
 * orders and subscriptions.
 * 
 * Business Rules:
 * - Only cancels subscriptions for initial (parent) orders, not renewal orders
 * - Skips if subscription is already canceled
 * - Uses FluentCart's standard cancelRemoteSubscription() method
 * - Logs all operations for audit trail
 * 
 * @package WPKJFluentCart\Alipay\Listeners
 */
class OrderCancelListener
{
    /**
     * Register event listener
     * 
     * @return void
     */
    public function register()
    {
        // Listen to FluentCart order status change event
        add_action('fluent_cart/order_status_changed_to_canceled', [$this, 'handleOrderCanceled'], 10, 1);
    }

    /**
     * Handle order canceled event
     * 
     * @param array $data Event data containing order information
     * @return void
     */
    public function handleOrderCanceled($data)
    {
        $order = Arr::get($data, 'order');
        
        if (!$order) {
            Logger::warning('Order Cancel Event: No order found in event data');
            return;
        }

        Logger::info('Order Canceled Event Received', [
            'order_id' => $order->id,
            'order_type' => $order->type ?? 'unknown',
            'payment_method' => $order->payment_method ?? 'unknown',
            'subscription_id' => $order->subscription_id ?? null
        ]);

        // Check if auto-cancel feature is enabled in settings
        if (!$this->isAutoCancelEnabled($order)) {
            Logger::info('Order Cancel Event: Auto-cancel subscription feature is disabled', [
                'order_id' => $order->id,
                'payment_method' => $order->payment_method
            ]);
            return;
        }

        // Only process if order has an associated subscription
        if (!$order->subscription_id) {
            Logger::debug('Order Cancel Event: No subscription associated', [
                'order_id' => $order->id
            ]);
            return;
        }

        // Skip renewal orders - only cancel subscription on parent order cancellation
        if ($order->type === 'renewal') {
            Logger::info('Order Cancel Event: Skipping renewal order', [
                'order_id' => $order->id,
                'subscription_id' => $order->subscription_id,
                'reason' => 'Renewal orders should not trigger subscription cancellation'
            ]);
            return;
        }

        // Get subscription
        $subscription = Subscription::find($order->subscription_id);
        
        if (!$subscription) {
            Logger::error('Order Cancel Event: Subscription not found', [
                'order_id' => $order->id,
                'subscription_id' => $order->subscription_id
            ]);
            return;
        }

        // Check if subscription is already canceled
        if ($subscription->status === Status::SUBSCRIPTION_CANCELED) {
            Logger::info('Order Cancel Event: Subscription already canceled', [
                'order_id' => $order->id,
                'subscription_id' => $subscription->id,
                'subscription_status' => $subscription->status
            ]);
            return;
        }

        // Only process Alipay payment orders
        // This prevents conflicts with other payment gateways' own cancellation logic
        if ($order->payment_method !== 'alipay') {
            Logger::debug('Order Cancel Event: Not an Alipay payment order', [
                'order_id' => $order->id,
                'payment_method' => $order->payment_method,
                'subscription_id' => $subscription->id
            ]);
            return;
        }

        // Cancel the subscription
        $this->cancelSubscription($subscription, $order);
    }

    /**
     * Cancel subscription
     * 
     * Uses FluentCart's standard cancelRemoteSubscription() method
     * which handles:
     * - Remote gateway cancellation (if applicable)
     * - Local status update
     * - Event triggering (SubscriptionCanceled)
     * - Activity logging
     * 
     * @param Subscription $subscription
     * @param Order $order
     * @return void
     */
    private function cancelSubscription($subscription, $order)
    {
        Logger::info('Canceling Subscription Due to Order Cancellation', [
            'subscription_id' => $subscription->id,
            'order_id' => $order->id,
            'current_status' => $subscription->status,
            'vendor_subscription_id' => $subscription->vendor_subscription_id ?? 'none'
        ]);

        try {
            // Use FluentCart's standard method
            $result = $subscription->cancelRemoteSubscription([
                'reason' => 'parent_order_canceled',
                'fire_hooks' => true,
                'note' => sprintf(
                    /* translators: %d: order ID */
                    __('Subscription automatically canceled because parent order #%d was canceled', 'wpkj-fluentcart-alipay-payment'),
                    $order->id
                )
            ]);

            // Check result
            if (is_wp_error($result)) {
                Logger::error('Failed to Cancel Subscription', [
                    'subscription_id' => $subscription->id,
                    'order_id' => $order->id,
                    'error_code' => $result->get_error_code(),
                    'error_message' => $result->get_error_message()
                ]);
                return;
            }

            // Get vendor cancellation result
            $vendorResult = Arr::get($result, 'vendor_result');
            
            if (is_wp_error($vendorResult)) {
                Logger::warning('Subscription Canceled Locally but Remote Cancellation Failed', [
                    'subscription_id' => $subscription->id,
                    'order_id' => $order->id,
                    'vendor_error' => $vendorResult->get_error_message(),
                    'note' => 'Local subscription status updated to canceled, but remote gateway cancellation failed'
                ]);
            } else {
                Logger::info('Subscription Canceled Successfully', [
                    'subscription_id' => $subscription->id,
                    'order_id' => $order->id,
                    'vendor_result' => 'success'
                ]);
            }

            // Add log to order
            $order->addLog(
                __('Subscription Auto-Canceled', 'wpkj-fluentcart-alipay-payment'),
                sprintf(
                    /* translators: %d: subscription ID */
                    __('Subscription #%d was automatically canceled due to order cancellation', 'wpkj-fluentcart-alipay-payment'),
                    $subscription->id
                ),
                'info'
            );

        } catch (\Exception $e) {
            Logger::error('Exception While Canceling Subscription', [
                'subscription_id' => $subscription->id,
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Check if auto-cancel subscription feature is enabled
     * 
     * @param Order $order
     * @return bool
     */
    private function isAutoCancelEnabled($order)
    {
        // Only process Alipay payment orders
        if ($order->payment_method !== 'alipay') {
            return false;
        }

        // Get gateway settings
        try {
            $gateway = \FluentCart\App\App::gateway('alipay');
            if (!$gateway) {
                return false;
            }

            $settings = $gateway->settings;
            if (!$settings) {
                return false;
            }

            // Check if auto-cancel is enabled (default: no - disabled)
            $autoCancelEnabled = $settings->get('auto_cancel_subscription_on_order_cancel', 'no');
            
            return $autoCancelEnabled === 'yes';

        } catch (\Exception $e) {
            Logger::error('Failed to Check Auto-Cancel Settings', [
                'order_id' => $order->id,
                'exception' => $e->getMessage()
            ]);
            
            // Default to disabled if we can't read settings (conservative approach)
            return false;
        }
    }
}
