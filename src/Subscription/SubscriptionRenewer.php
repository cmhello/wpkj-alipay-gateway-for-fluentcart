<?php

namespace WPKJFluentCart\Alipay\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use WPKJFluentCart\Alipay\Utils\Logger;
use FluentCart\Framework\Support\Arr;

/**
 * Subscription Renewal and Retry Handler
 * 
 * Handles automatic renewal retry logic for failed subscription payments
 */
class SubscriptionRenewer
{
    /**
     * Maximum retry attempts for failed renewal
     * 
     * @var int
     */
    private const MAX_RETRIES = 3;

    /**
     * Retry interval in seconds (1 day)
     * 
     * @var int
     */
    private const RETRY_INTERVAL = DAY_IN_SECONDS;

    /**
     * Retry a failed renewal payment
     * 
     * @param Subscription $subscription Subscription model
     * @param int $attemptNumber Current attempt number (0-based)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function retryFailedRenewal($subscription, $attemptNumber = 0)
    {
        // Validate subscription
        if ($subscription->current_payment_method !== 'alipay') {
            return new \WP_Error(
                'invalid_payment_method',
                __('Subscription is not using Alipay payment method.', 'wpkj-alipay-gateway-for-fluentcart')
            );
        }

        // Check if we've exceeded max retries
        if ($attemptNumber >= self::MAX_RETRIES) {
            Logger::warning('Max Retry Attempts Reached', [
                'subscription_id' => $subscription->id,
                'attempts' => $attemptNumber
            ]);

            // Mark subscription as past due
            $this->markAsPastDue($subscription);
            return false;
        }

        Logger::info('Retrying Failed Renewal', [
            'subscription_id' => $subscription->id,
            'attempt' => $attemptNumber + 1,
            'max_retries' => self::MAX_RETRIES
        ]);

        try {
            // Check if recurring agreement is available
            if (!$subscription->vendor_subscription_id) {
                Logger::warning('No Recurring Agreement Found', [
                    'subscription_id' => $subscription->id
                ]);

                // Cannot auto-retry without agreement, send manual payment notification
                $this->sendManualPaymentNotification($subscription);
                return false;
            }

            // Attempt to deduct payment via recurring agreement
            $settings = $this->getSettings();
            $agreement = new AlipayRecurringAgreement($settings);
            
            // Calculate amount in cents
            $amount = $subscription->getCurrentRenewalAmount();
            
            // Prepare order data for executeAgreementPay
            $orderData = [
                'transaction_uuid' => $this->generateRetryTradeNo($subscription, $attemptNumber),
                'out_trade_no' => $this->generateRetryTradeNo($subscription, $attemptNumber)
            ];
            
            $deductResult = $agreement->executeAgreementPay($subscription, $amount, $orderData);

            if (is_wp_error($deductResult)) {
                Logger::error('Renewal Retry Failed', [
                    'subscription_id' => $subscription->id,
                    'attempt' => $attemptNumber + 1,
                    'error' => $deductResult->get_error_message()
                ]);

                // Schedule next retry if not at max attempts
                if ($attemptNumber < self::MAX_RETRIES - 1) {
                    $this->scheduleNextRetry($subscription, $attemptNumber + 1);
                } else {
                    $this->markAsPastDue($subscription);
                }

                return $deductResult;
            }

            // Deduction successful
            Logger::info('Renewal Retry Successful', [
                'subscription_id' => $subscription->id,
                'attempt' => $attemptNumber + 1,
                'trade_no' => Arr::get($deductResult, 'trade_no')
            ]);

            // Update subscription status
            $subscription->update([
                'status' => Status::SUBSCRIPTION_ACTIVE,
                'next_billing_date' => $subscription->guessNextBillingDate(true)
            ]);

            // Send success notification
            do_action('wpkj_fc_alipay_renewal_retry_success', $subscription, $attemptNumber);

            return true;

        } catch (\Exception $e) {
            Logger::error('Renewal Retry Exception', [
                'subscription_id' => $subscription->id,
                'attempt' => $attemptNumber + 1,
                'error' => $e->getMessage()
            ]);

            // Schedule next retry
            if ($attemptNumber < self::MAX_RETRIES - 1) {
                $this->scheduleNextRetry($subscription, $attemptNumber + 1);
            } else {
                $this->markAsPastDue($subscription);
            }

            return new \WP_Error('renewal_retry_exception', $e->getMessage());
        }
    }

    /**
     * Schedule next retry attempt
     * 
     * @param Subscription $subscription Subscription model
     * @param int $nextAttempt Next attempt number
     * @return void
     */
    private function scheduleNextRetry($subscription, $nextAttempt)
    {
        $retryTime = time() + self::RETRY_INTERVAL;

        // Schedule WordPress cron event
        wp_schedule_single_event(
            $retryTime,
            'wpkj_fc_alipay_retry_renewal',
            [$subscription->id, $nextAttempt]
        );

        Logger::info('Next Retry Scheduled', [
            'subscription_id' => $subscription->id,
            'next_attempt' => $nextAttempt + 1,
            'scheduled_time' => gmdate('Y-m-d H:i:s', $retryTime)
        ]);

        // Update subscription meta
        $subscription->updateMeta('last_retry_attempt', $nextAttempt);
        $subscription->updateMeta('next_retry_time', $retryTime);
    }

    /**
     * Mark subscription as past due after all retries failed
     * 
     * @param Subscription $subscription Subscription model
     * @return void
     */
    private function markAsPastDue($subscription)
    {
        $subscription->update([
            'status' => Status::SUBSCRIPTION_PAST_DUE
        ]);

        Logger::warning('Subscription Marked as Past Due', [
            'subscription_id' => $subscription->id
        ]);

        // Send notification to customer
        do_action('wpkj_fc_alipay_renewal_failed', $subscription);
        
        // Send notification to admin
        do_action('wpkj_fc_alipay_renewal_failed_admin', $subscription);
        
        // Clear retry metadata
        $subscription->deleteMeta('last_retry_attempt');
        $subscription->deleteMeta('next_retry_time');
    }

    /**
     * Send manual payment notification to customer
     * 
     * @param Subscription $subscription Subscription model
     * @return void
     */
    private function sendManualPaymentNotification($subscription)
    {
        Logger::info('Sending Manual Payment Notification', [
            'subscription_id' => $subscription->id,
            'customer_email' => $subscription->customer->email
        ]);

        do_action('wpkj_fc_alipay_send_manual_payment_notice', $subscription);
    }

    /**
     * Calculate renewal amount
     * 
     * @param Subscription $subscription Subscription model
     * @return float Renewal amount
     */
    private function calculateRenewalAmount($subscription)
    {
        return $subscription->getCurrentRenewalAmount() / 100;
    }

    /**
     * Get settings instance
     * 
     * @return \WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase
     */
    private function getSettings()
    {
        return new \WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase();
    }

    /**
     * Generate unique trade number for retry
     * 
     * @param Subscription $subscription Subscription model
     * @param int $attemptNumber Attempt number
     * @return string Unique trade number
     */
    private function generateRetryTradeNo($subscription, $attemptNumber)
    {
        // Format: uuid-retry-{attempt}-{timestamp}
        return sprintf(
            '%s-retry-%d-%s',
            str_replace('-', '', $subscription->uuid),
            $attemptNumber,
            time()
        );
    }

    /**
     * Initialize retry hooks
     * 
     * @return void
     */
    public static function init()
    {
        // Register cron action
        add_action('wpkj_fc_alipay_retry_renewal', [__CLASS__, 'handleCronRetry'], 10, 2);
    }

    /**
     * Handle cron retry event
     * 
     * @param int $subscriptionId Subscription ID
     * @param int $attemptNumber Attempt number
     * @return void
     */
    public static function handleCronRetry($subscriptionId, $attemptNumber)
    {
        $subscription = Subscription::find($subscriptionId);
        
        if (!$subscription) {
            Logger::error('Subscription Not Found for Retry', [
                'subscription_id' => $subscriptionId
            ]);
            return;
        }

        $renewer = new self();
        $renewer->retryFailedRenewal($subscription, $attemptNumber);
    }
}
