<?php

namespace WPKJFluentCart\Alipay\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Alipay Subscriptions Module
 * 
 * Handles recurring payment subscriptions for Alipay
 * Note: Alipay doesn't have native recurring payment API like Stripe
 * This implementation uses scheduled manual charges at billing intervals
 */
class AlipaySubscriptions extends AbstractSubscriptionModule
{
    /**
     * @var AlipaySettingsBase
     */
    private $settings;

    /**
     * Constructor
     */
    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Re-sync subscription status from Alipay
     * 
     * Since Alipay doesn't have native subscription API,
     * we check the latest payment transaction status
     * 
     * @param Subscription $subscriptionModel
     * @return \WP_Error|array
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'alipay') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Alipay as payment method.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        $order = $subscriptionModel->order;

        try {
            // Get the latest transaction for this subscription
            $latestTransaction = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('payment_method', 'alipay')
                ->orderBy('id', 'DESC')
                ->first();

            if (!$latestTransaction || !$latestTransaction->vendor_charge_id) {
                Logger::warning('No valid transaction found for subscription sync', [
                    'subscription_id' => $subscriptionModel->id,
                    'order_id' => $order->id
                ]);
                
                return new \WP_Error(
                    'no_transaction',
                    __('No valid Alipay transaction found for this subscription.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            // Query transaction status from Alipay
            $api = new AlipayAPI($this->settings);
            $outTradeNo = $latestTransaction->meta['out_trade_no'] ?? str_replace('-', '', $latestTransaction->uuid);
            
            $tradeData = $api->queryTrade($outTradeNo);

            if (is_wp_error($tradeData)) {
                return $tradeData;
            }

            $tradeStatus = Arr::get($tradeData, 'trade_status');
            $tradeNo = Arr::get($tradeData, 'trade_no');

            Logger::info('Subscription Sync from Alipay', [
                'subscription_id' => $subscriptionModel->id,
                'trade_status' => $tradeStatus,
                'trade_no' => $tradeNo
            ]);

            // Update subscription based on payment status
            $subscriptionStatus = $this->mapAlipayStatusToSubscription($tradeStatus);
            
            if ($subscriptionStatus && $subscriptionModel->status !== $subscriptionStatus) {
                $subscriptionModel->status = $subscriptionStatus;
                $subscriptionModel->save();

                Logger::info('Subscription Status Updated', [
                    'subscription_id' => $subscriptionModel->id,
                    'new_status' => $subscriptionStatus,
                    'alipay_status' => $tradeStatus
                ]);
            }

            return [
                'status' => 'success',
                'message' => __('Subscription synced successfully.', 'wpkj-fluentcart-alipay-payment'),
                'data' => [
                    'trade_status' => $tradeStatus,
                    'subscription_status' => $subscriptionModel->status
                ]
            ];

        } catch (\Exception $e) {
            Logger::error('Subscription Sync Error', [
                'subscription_id' => $subscriptionModel->id,
                'error' => $e->getMessage()
            ]);

            return new \WP_Error('sync_error', $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     * 
     * For Alipay subscriptions, we just update the local status
     * since there's no remote subscription to cancel
     * 
     * @param string $vendorSubscriptionId
     * @param array $args
     * @return array|\WP_Error
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        try {
            // Find subscription by vendor ID
            $subscription = Subscription::query()
                ->where('vendor_subscription_id', $vendorSubscriptionId)
                ->first();

            if (!$subscription) {
                return new \WP_Error(
                    'subscription_not_found',
                    __('Subscription not found.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            Logger::info('Alipay Subscription Cancellation', [
                'subscription_id' => $subscription->id,
                'vendor_subscription_id' => $vendorSubscriptionId,
                'reason' => Arr::get($args, 'reason', 'User requested')
            ]);

            // Update local subscription status
            $subscription->status = Status::SUBSCRIPTION_CANCELED;
            $subscription->canceled_at = current_time('mysql');
            $subscription->save();

            return [
                'status' => 'success',
                'message' => __('Subscription cancelled successfully.', 'wpkj-fluentcart-alipay-payment')
            ];

        } catch (\Exception $e) {
            Logger::error('Subscription Cancellation Error', [
                'vendor_subscription_id' => $vendorSubscriptionId,
                'error' => $e->getMessage()
            ]);

            return new \WP_Error('cancel_error', $e->getMessage());
        }
    }

    /**
     * Cancel subscription (alternative method signature)
     * 
     * @param array $data
     * @param object $order
     * @param Subscription $subscription
     * @return void
     * @throws \Exception
     */
    public function cancelSubscription($data, $order, $subscription)
    {
        $result = $this->cancel($subscription->vendor_subscription_id, [
            'reason' => Arr::get($data, 'reason', 'User requested cancellation')
        ]);

        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
    }

    /**
     * Cancel subscription when auto-renew is turned off
     * 
     * @param Subscription $subscription
     * @return void
     */
    public function cancelAutoRenew($subscription)
    {
        if ($subscription->current_payment_method !== 'alipay') {
            return;
        }

        Logger::info('Alipay Auto-Renew Cancelled', [
            'subscription_id' => $subscription->id,
            'vendor_subscription_id' => $subscription->vendor_subscription_id
        ]);

        // For Alipay, we just need to update local status
        // The scheduled renewal job will check this status
        $subscription->status = Status::SUBSCRIPTION_CANCELED;
        $subscription->canceled_at = current_time('mysql');
        $subscription->save();
    }

    /**
     * Cancel subscription on plan change
     * 
     * @param string $vendorSubscriptionId
     * @param int $parentOrderId
     * @param int $subscriptionId
     * @param string $reason
     * @return void
     */
    public function cancelOnPlanChange($vendorSubscriptionId, $parentOrderId, $subscriptionId, $reason)
    {
        Logger::info('Alipay Subscription Cancelled on Plan Change', [
            'subscription_id' => $subscriptionId,
            'vendor_subscription_id' => $vendorSubscriptionId,
            'parent_order_id' => $parentOrderId,
            'reason' => $reason
        ]);

        // For Alipay, local status update is sufficient
        $subscription = Subscription::find($subscriptionId);
        if ($subscription) {
            $subscription->status = Status::SUBSCRIPTION_CANCELED;
            $subscription->canceled_at = current_time('mysql');
            $subscription->save();
        }
    }

    /**
     * Cancel on switch payment method
     * 
     * @param string $currentVendorSubscriptionId
     * @param int $parentOrderId
     * @param string $vendorSubscriptionId
     * @param string $newPaymentMethod
     * @param string $reason
     * @return void
     */
    public function cancelOnSwitchPaymentMethod($currentVendorSubscriptionId, $parentOrderId, $vendorSubscriptionId, $newPaymentMethod, $reason)
    {
        Logger::info('Alipay Subscription Cancelled on Payment Method Switch', [
            'current_vendor_subscription_id' => $currentVendorSubscriptionId,
            'new_payment_method' => $newPaymentMethod,
            'reason' => $reason
        ]);

        $this->cancel($currentVendorSubscriptionId, ['reason' => $reason]);
    }

    /**
     * Map Alipay trade status to subscription status
     * 
     * @param string $alipayStatus
     * @return string|null
     */
    private function mapAlipayStatusToSubscription($alipayStatus)
    {
        $statusMap = [
            'TRADE_SUCCESS' => Status::SUBSCRIPTION_ACTIVE,
            'TRADE_FINISHED' => Status::SUBSCRIPTION_ACTIVE,
            'WAIT_BUYER_PAY' => Status::SUBSCRIPTION_PENDING,
            'TRADE_CLOSED' => Status::SUBSCRIPTION_CANCELED,
        ];

        return $statusMap[$alipayStatus] ?? null;
    }

    /**
     * Reactivate subscription
     * 
     * @param array $data
     * @param int $subscriptionId
     * @return void
     * @throws \Exception
     */
    public function reactivateSubscription($data, $subscriptionId)
    {
        $subscription = Subscription::find($subscriptionId);
        
        if (!$subscription) {
            throw new \Exception(__('Subscription not found.', 'wpkj-fluentcart-alipay-payment'));
        }

        if ($subscription->current_payment_method !== 'alipay') {
            throw new \Exception(__('This subscription is not using Alipay.', 'wpkj-fluentcart-alipay-payment'));
        }

        Logger::info('Alipay Subscription Reactivation', [
            'subscription_id' => $subscriptionId
        ]);

        // Update subscription status
        $subscription->status = Status::SUBSCRIPTION_ACTIVE;
        $subscription->canceled_at = null;
        $subscription->save();

        // Schedule next billing
        $this->scheduleNextRenewal($subscription);
    }

    /**
     * Schedule next renewal for subscription
     * 
     * @param Subscription $subscription
     * @return void
     */
    private function scheduleNextRenewal(Subscription $subscription)
    {
        // This will be handled by FluentCart's built-in cron system
        // We just need to ensure the next billing date is set correctly
        
        if (!$subscription->next_billing_date) {
            $interval = $subscription->billing_interval;
            $nextBillingDate = date('Y-m-d H:i:s', strtotime("+1 {$interval}"));
            
            $subscription->next_billing_date = $nextBillingDate;
            $subscription->save();

            Logger::info('Next Billing Date Scheduled', [
                'subscription_id' => $subscription->id,
                'next_billing_date' => $nextBillingDate
            ]);
        }
    }
}
