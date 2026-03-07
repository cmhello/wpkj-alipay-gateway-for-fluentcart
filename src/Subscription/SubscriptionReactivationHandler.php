<?php

namespace WPKJFluentCart\Alipay\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Subscription Reactivation Handler (Alipay)
 *
 * Handles the subscription reactivation / manual-renewal flow for Alipay.
 * Intercepts ?fluent-cart=reactivate-subscription requests, pre-creates a
 * pending renewal order + transaction, then passes everything to
 * AlipaySubscriptionProcessor to generate the payment redirect URL.
 *
 * URL format:
 *   /?fluent-cart=reactivate-subscription&subscription_hash={uuid}&method=alipay
 */
class SubscriptionReactivationHandler
{
    /**
     * @var AlipaySettingsBase
     */
    private $settings;

    /**
     * @param AlipaySettingsBase $settings
     */
    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public function register()
    {
        // Priority 9 – runs after RenewalCheckoutPage (priority 5) but before
        // FluentCart Pro's own handler (priority 10).
        // Must hook on fluent_cart_action_reactivate-subscription because FluentCart
        // processes ?fluent-cart=... at init time and die()s before template_redirect fires.
        add_action('fluent_cart_action_reactivate-subscription', [$this, 'handleReactivation'], 9);
    }

    /**
     * Handle subscription reactivation / manual-renewal request.
     *
     * @return void
     */
    public function handleReactivation()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['fluent-cart']) || wp_unslash($_GET['fluent-cart']) !== 'reactivate-subscription') {
            return;
        }

        // Only handle requests explicitly directed at Alipay.
        // RenewalCheckoutPage (priority 5) already showed the selection page; by
        // the time we run (priority 10) the user has chosen ?method=alipay.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $requestedMethod = isset($_GET['method']) ? sanitize_key(wp_unslash($_GET['method'])) : '';
        if ($requestedMethod !== 'alipay') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
        // Support both FluentCart standard (?subscription_hash) and WAAS email format (?subscription_uuid).
        $subscriptionUuid = '';
        if (isset($_GET['subscription_hash']) && '' !== $_GET['subscription_hash']) {
            $subscriptionUuid = sanitize_text_field(wp_unslash($_GET['subscription_hash']));
        } elseif (isset($_GET['subscription_uuid']) && '' !== $_GET['subscription_uuid']) {
            $subscriptionUuid = sanitize_text_field(wp_unslash($_GET['subscription_uuid']));
        }

        if (empty($subscriptionUuid)) {
            wp_die(
                esc_html__('Invalid renewal request: missing subscription identifier.', 'wpkj-alipay-gateway-for-fluentcart'),
                esc_html__('Error', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 400]
            );
        }

        // Require the user to be logged in.
        if (!is_user_logged_in()) {
            $currentUrl = add_query_arg([
                'fluent-cart'       => 'reactivate-subscription',
                'subscription_hash' => $subscriptionUuid,
            ], home_url('/'));
            wp_redirect(wp_login_url($currentUrl));
            exit;
        }

        // Find subscription by UUID.
        $subscription = Subscription::query()->where('uuid', $subscriptionUuid)->first();
        if (!$subscription) {
            wp_die(
                esc_html__('Subscription not found.', 'wpkj-alipay-gateway-for-fluentcart'),
                esc_html__('Error', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 404]
            );
        }

        // Verify the subscription belongs to the currently logged-in user.
        if (!$this->verifyOwnership($subscription)) {
            Logger::warning('Subscription Reactivation Unauthorized Access', [
                'subscription_id' => $subscription->id,
                'user_id'         => get_current_user_id(),
            ]);
            wp_die(
                esc_html__('You do not have permission to renew this subscription.', 'wpkj-alipay-gateway-for-fluentcart'),
                esc_html__('Permission Denied', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 403]
            );
        }

        // Verify the subscription status allows renewal.
        if (!$this->canRenew($subscription)) {
            wp_die(
                esc_html__('This subscription cannot be renewed at this time.', 'wpkj-alipay-gateway-for-fluentcart'),
                esc_html__('Error', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 422]
            );
        }

        Logger::info('Processing Subscription Reactivation via Alipay', [
            'subscription_id'     => $subscription->id,
            'subscription_status' => $subscription->status,
        ]);

        // Get or create a pending renewal order.
        $renewalOrder = $this->getOrCreatePendingRenewalOrder($subscription);
        if (!$renewalOrder) {
            Logger::error('Failed to Create Renewal Order', ['subscription_id' => $subscription->id]);
            wp_die(
                esc_html__('Failed to create renewal order. Please try again.', 'wpkj-alipay-gateway-for-fluentcart'),
                esc_html__('Error', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 500]
            );
        }

        // PaymentInstance auto-loads the latest charge transaction for the order.
        $paymentInstance = new PaymentInstance($renewalOrder);

        // If no transaction was found, create one now.
        if (!$paymentInstance->transaction) {
            $pendingTransaction = $this->createPendingTransaction($renewalOrder, $subscription);
            if (!$pendingTransaction) {
                Logger::error('Failed to Create Renewal Transaction', [
                    'order_id'        => $renewalOrder->id,
                    'subscription_id' => $subscription->id,
                ]);
                wp_die(
                    esc_html__('Failed to create renewal transaction. Please try again.', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html__('Error', 'wpkj-alipay-gateway-for-fluentcart'),
                    ['response' => 500]
                );
            }
            $paymentInstance->setTransaction($pendingTransaction);
        }

        // Process payment via the Alipay subscription processor.
        $processor = new AlipaySubscriptionProcessor($this->settings);
        $result    = $processor->processSubscription($paymentInstance);

        if ($result instanceof \WP_Error) {
            Logger::error('Alipay Reactivation Payment WP_Error', [
                'subscription_id' => $subscription->id,
                'error'           => $result->get_error_message(),
            ]);
            wp_die(
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('Payment error: %s', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html($result->get_error_message())
                ),
                esc_html__('Payment Error', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 500]
            );
        }

        if (isset($result['status']) && $result['status'] === 'failed') {
            Logger::error('Alipay Reactivation Payment Failed', [
                'subscription_id' => $subscription->id,
                'message'         => $result['message'] ?? '',
            ]);
            wp_die(
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('Payment error: %s', 'wpkj-alipay-gateway-for-fluentcart'),
                    esc_html($result['message'] ?? __('Unknown error', 'wpkj-alipay-gateway-for-fluentcart'))
                ),
                esc_html__('Payment Error', 'wpkj-alipay-gateway-for-fluentcart'),
                ['response' => 500]
            );
        }

        // QR code / F2F redirect.
        if (!empty($result['redirect_to'])) {
            wp_redirect(esc_url_raw($result['redirect_to']));
            exit;
        }

        // Alipay page / wap payment redirect.
        if (!empty($result['redirect_url'])) {
            wp_redirect(esc_url_raw($result['redirect_url']));
            exit;
        }

        // Fallback — should not normally reach here.
        wp_die(
            esc_html__('Unable to process payment. Please try again.', 'wpkj-alipay-gateway-for-fluentcart'),
            esc_html__('Error', 'wpkj-alipay-gateway-for-fluentcart'),
            ['response' => 500]
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Verify the subscription belongs to the currently logged-in user.
     *
     * @param Subscription $subscription
     * @return bool
     */
    private function verifyOwnership(Subscription $subscription)
    {
        $currentUserId = (int) get_current_user_id();
        if (!$currentUserId) {
            return false;
        }

        $customer = $subscription->customer;
        return $customer && (int) $customer->user_id === $currentUserId;
    }

    /**
     * Check whether the subscription's current status allows renewal or
     * reactivation.
     *
     * @param Subscription $subscription
     * @return bool
     */
    private function canRenew(Subscription $subscription)
    {
        $allowedStatuses = [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_FAILING,
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_PAUSED,
            Status::SUBSCRIPTION_EXPIRING,
            Status::SUBSCRIPTION_PAST_DUE,
        ];

        return in_array($subscription->status, $allowedStatuses, true);
    }

    /**
     * Return an existing pending Alipay renewal order (created within the last
     * 2 hours) or create a fresh one.
     *
     * @param Subscription $subscription
     * @return Order|null
     */
    private function getOrCreatePendingRenewalOrder(Subscription $subscription)
    {
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS);

        $existingOrder = Order::query()
            ->where('parent_id', $subscription->parent_order_id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->where('status', Status::ORDER_ON_HOLD)
            ->where('payment_method', 'alipay')
            ->where('created_at', '>=', $twoHoursAgo)
            ->orderBy('id', 'desc')
            ->first();

        if ($existingOrder) {
            Logger::info('Reusing Existing Pending Alipay Renewal Order', [
                'order_id'        => $existingOrder->id,
                'subscription_id' => $subscription->id,
            ]);
            return $existingOrder;
        }

        return $this->createPendingRenewalOrder($subscription);
    }

    /**
     * Create a new pending renewal order for the subscription.
     *
     * @param Subscription $subscription
     * @return Order|null
     */
    private function createPendingRenewalOrder(Subscription $subscription)
    {
        $parentOrder = Order::query()->find($subscription->parent_order_id);
        if (!$parentOrder) {
            Logger::error('Parent Order Not Found for Subscription', [
                'subscription_id' => $subscription->id,
                'parent_order_id' => $subscription->parent_order_id,
            ]);
            return null;
        }

        $renewalAmount = (int) $subscription->getCurrentRenewalAmount();
        $taxTotal      = (int) ($subscription->recurring_tax_total ?? 0);
        $subtotal      = $renewalAmount - $taxTotal;

        $orderData = [
            'parent_id'      => $parentOrder->id,
            'customer_id'    => $subscription->customer_id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'payment_method' => 'alipay',
            'currency'       => $parentOrder->currency,
            'tax_behavior'   => $parentOrder->tax_behavior,
            'subtotal'       => $subtotal,
            'tax_total'      => $taxTotal,
            'total_amount'   => $renewalAmount,
            'total_paid'     => 0,
            'mode'           => $parentOrder->mode,
            'config'         => [],
        ];

        $renewalOrder = Order::query()->create($orderData);

        if (!$renewalOrder) {
            Logger::error('Order::create() Returned Null', [
                'subscription_id' => $subscription->id,
            ]);
            return null;
        }

        // Create the accompanying order item.
        $this->createOrderItem($renewalOrder, $subscription, $parentOrder, $subtotal, $taxTotal, $renewalAmount);

        Logger::info('Pending Alipay Renewal Order Created', [
            'order_id'        => $renewalOrder->id,
            'subscription_id' => $subscription->id,
            'amount'          => $renewalAmount,
        ]);

        return $renewalOrder;
    }

    /**
     * Create an order item linked to the renewal order.
     *
     * @param Order        $renewalOrder
     * @param Subscription $subscription
     * @param Order        $parentOrder
     * @param int          $subtotal
     * @param int          $taxTotal
     * @param int          $renewalAmount
     * @return void
     */
    private function createOrderItem(
        Order        $renewalOrder,
        Subscription $subscription,
        Order        $parentOrder,
        int          $subtotal,
        int          $taxTotal,
        int          $renewalAmount
    ) {
        $product = $subscription->product;

        $parentOrderItem = OrderItem::query()
            ->where('order_id', $parentOrder->id)
            ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
            ->first();

        $fulfillmentType = $parentOrderItem ? $parentOrderItem->fulfillment_type : 'digital';

        OrderItem::query()->create([
            'order_id'         => $renewalOrder->id,
            'post_id'          => $subscription->product_id,
            'object_id'        => $subscription->variation_id,
            'payment_type'     => Status::ORDER_TYPE_SUBSCRIPTION,
            'post_title'       => $product && $product->post_title
                                    ? $product->post_title
                                    : ($subscription->item_name ?? ''),
            'title'            => '',
            'quantity'         => 1,
            'fulfillment_type' => $fulfillmentType,
            'unit_price'       => $subtotal,
            'subtotal'         => $subtotal,
            'tax_amount'       => $taxTotal,
            'line_total'       => $renewalAmount,
            'line_meta'        => [],
            'other_info'       => [],
        ]);
    }

    /**
     * Create a pending charge transaction for the renewal order.
     *
     * @param Order        $renewalOrder
     * @param Subscription $subscription
     * @return OrderTransaction|null
     */
    private function createPendingTransaction(Order $renewalOrder, Subscription $subscription)
    {
        $transaction = OrderTransaction::query()->create([
            'order_id'         => $renewalOrder->id,
            'subscription_id'  => $subscription->id,
            'order_type'       => Status::ORDER_TYPE_RENEWAL,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method'   => 'alipay',
            'payment_mode'     => $renewalOrder->mode,
            'status'           => Status::TRANSACTION_PENDING,
            'currency'         => $renewalOrder->currency,
            'total'            => $renewalOrder->total_amount,
            'meta'             => [],
        ]);

        if (!$transaction) {
            Logger::error('OrderTransaction::create() Returned Null', [
                'order_id'        => $renewalOrder->id,
                'subscription_id' => $subscription->id,
            ]);
            return null;
        }

        Logger::info('Pending Alipay Renewal Transaction Created', [
            'transaction_id'  => $transaction->id,
            'order_id'        => $renewalOrder->id,
            'subscription_id' => $subscription->id,
        ]);

        return $transaction;
    }
}
