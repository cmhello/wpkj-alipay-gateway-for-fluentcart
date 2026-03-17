<?php

namespace WPKJFluentCart\Alipay\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService as FluentCartSubscriptionService;
use FluentCart\App\Services\Payments\PaymentHelper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Subscription Service
 * 
 * Centralized service for handling subscription-related operations
 * Eliminates code duplication across NotifyHandler and PaymentStatusChecker
 * 
 * This service provides unified logic for:
 * - Detecting subscription transactions
 * - Processing subscription payment success
 * - Managing subscription billing cycles
 * - Calculating next billing dates
 */
class SubscriptionService
{
    /**
     * Check if transaction is for a subscription
     * 
     * @param OrderTransaction $transaction
     * @return bool
     */
    public static function isSubscriptionTransaction($transaction)
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
     * Uses FluentCart's SubscriptionService::syncSubscriptionStates() for all subscription updates
     * This ensures:
     * - Automatic bill_count calculation from database
     * - Proper EOT (End of Term) detection
     * - Correct next_billing_date management
     * - Status change event triggering
     * 
     * @param OrderTransaction $transaction
     * @param array $paymentData Payment data from Alipay
     * @param string $source Confirmation source (webhook, polling, return_handler)
     * @return bool True if subscription was processed, false otherwise
     */
    public static function handleSubscriptionPaymentSuccess($transaction, $paymentData, $source = 'unknown')
    {
        // Get subscription
        $subscriptionId = self::getSubscriptionId($transaction);
        
        if (!$subscriptionId) {
            Logger::warning('Subscription ID Not Found in Transaction', [
                'transaction_uuid' => $transaction->uuid,
                'source' => $source
            ]);
            return false;
        }

        $subscription = Subscription::find($subscriptionId);
        
        if (!$subscription) {
            Logger::error('Subscription Not Found', [
                'subscription_id' => $subscriptionId,
                'transaction_uuid' => $transaction->uuid,
                'source' => $source
            ]);
            return false;
        }

        $order = $transaction->order;
        
        Logger::info('Processing Subscription Payment Success', [
            'subscription_id' => $subscriptionId,
            'trade_no' => $paymentData['trade_no'] ?? '',
            'order_type' => $order->type ?? 'unknown',
            'current_status' => $subscription->status,
            'source' => $source
        ]);

        // Update subscription status and bill_count using FluentCart standard method
        // This automatically:
        // - Calculates bill_count from database transactions
        // - Handles EOT (End of Term) detection
        // - Updates next_billing_date if needed
        // - Triggers status change events
        
        if ($order && $order->type === 'renewal') {
            // For renewal orders: use syncSubscriptionStates to auto-calculate bill_count
            $updateArgs = [
                'status' => Status::SUBSCRIPTION_ACTIVE,
                'next_billing_date' => self::calculateNextBillingDate($subscription)
            ];
            
            $subscription = FluentCartSubscriptionService::syncSubscriptionStates($subscription, $updateArgs);
            
            Logger::info('Subscription Updated After Renewal (via syncSubscriptionStates)', [
                'subscription_id' => $subscription->id,
                'bill_count' => $subscription->bill_count,
                'next_billing_date' => $subscription->next_billing_date,
                'status' => $subscription->status,
                'source' => $source
            ]);

            // Fire WAAS integration hook for renewal.
            // IMPORTANT: WAAS FluentCart_Integration listens to 'fluentcart/subscription_renewed'
            // (no underscore between "fluent" and "cart"). Alipay bypasses FluentCart's
            // Stripe/PayPal-only events, so we must manually fire the WAAS hook here.
            do_action( 'fluentcart/subscription_renewed', $subscription );

        } else {
            // For initial subscription payment: use syncSubscriptionStates to set bill_count
            $updateArgs = [
                'status' => Status::SUBSCRIPTION_ACTIVE
            ];
            
            // Only set next_billing_date if not already set
            if (empty($subscription->next_billing_date)) {
                $updateArgs['next_billing_date'] = self::calculateNextBillingDate($subscription);
            }
            
            $subscription = FluentCartSubscriptionService::syncSubscriptionStates($subscription, $updateArgs);
            
            Logger::info('Subscription Activated After Initial Payment (via syncSubscriptionStates)', [
                'subscription_id' => $subscription->id,
                'bill_count' => $subscription->bill_count,
                'next_billing_date' => $subscription->next_billing_date,
                'trial_days' => $subscription->trial_days,
                'billing_interval' => $subscription->billing_interval,
                'source' => $source
            ]);

            // Fire WAAS integration hook for initial subscription activation.
            // IMPORTANT: WAAS FluentCart_Integration listens to 'fluentcart/subscription_activated'
            // (no underscore between "fluent" and "cart"), while FluentCart's own SubscriptionActivated
            // event fires 'fluent_cart/subscription_activated' (with underscore). They are different hooks.
            // Alipay bypasses FluentCart's Stripe/PayPal-only SubscriptionActivated event entirely,
            // so we must manually fire the WAAS hook here to create the WAAS subscription record.
            do_action( 'fluentcart/subscription_activated', $subscription, $order );
        }

        return true;
    }

    /**
     * Calculate next billing date based on subscription interval
     * 
     * Uses FluentCart's built-in guessNextBillingDate() method for consistency
     * 
     * @param Subscription $subscription
     * @return string Y-m-d H:i:s format
     */
    private static function calculateNextBillingDate($subscription)
    {
        // When next_billing_date is already set, advance it by exactly one billing interval.
        // guessNextBillingDate(true) bases the calculation on renewal_order.created_at + interval,
        // which gives the wrong result when the user renews before the actual billing date.
        // Example: next_billing_date = 2027-03-17, user renews on 2026-03-17 (early/test renewal).
        //   guessNextBillingDate: 2026-03-17 + 1 year = 2027-03-17  ← incorrect (no advance)
        //   Correct result:       2027-03-17 + 1 year = 2028-03-17  ✓
        if ( $subscription->next_billing_date ) {
            $days = PaymentHelper::getIntervalDays( $subscription->billing_interval );
            return gmdate( 'Y-m-d H:i:s', strtotime( $subscription->next_billing_date ) + $days * DAY_IN_SECONDS );
        }

        // Fallback for first-time activation (no existing next_billing_date).
        return $subscription->guessNextBillingDate( true );
    }

    /**
     * Get subscription ID from transaction
     * 
     * @param OrderTransaction $transaction
     * @return int|null
     */
    public static function getSubscriptionId($transaction)
    {
        // Try transaction meta first
        if (isset($transaction->meta['subscription_id'])) {
            return $transaction->meta['subscription_id'];
        }

        // Try order subscription_id
        if ($transaction->order && $transaction->order->subscription_id) {
            return $transaction->order->subscription_id;
        }

        return null;
    }

    /**
     * Log subscription payment confirmation
     * 
     * Standardized logging for subscription payments
     * 
     * @param int $subscriptionId
     * @param string $tradeNo
     * @param string $orderType
     * @param string $source
     * @return void
     */
    public static function logSubscriptionConfirmation($subscriptionId, $tradeNo, $orderType, $source)
    {
        Logger::info('Subscription Payment Confirmed', [
            'subscription_id' => $subscriptionId,
            'trade_no' => $tradeNo,
            'order_type' => $orderType,
            'source' => $source
        ]);
    }
}
