<?php

namespace WPKJFluentCart\Alipay\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Detector\ClientDetector;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Services\EncodingService;
use WPKJFluentCart\Alipay\Subscription\AlipayRecurringAgreement;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Alipay Subscription Processor
 * 
 * Handles subscription payment processing for Alipay
 * 
 * IMPORTANT: Alipay doesn't have native recurring payment API
 * Implementation strategy:
 * 1. Initial payment: Process like regular payment
 * 2. Store subscription agreement information
 * 3. For renewals: Create new payment with stored customer info
 * 4. Use FluentCart's cron system to schedule renewals
 */
class AlipaySubscriptionProcessor
{
    /**
     * @var AlipaySettingsBase
     */
    private $settings;

    /**
     * @var AlipayAPI
     */
    private $api;

    /**
     * @var AlipayRecurringAgreement
     */
    private $recurring;

    /**
     * Constructor
     * 
     * @param AlipaySettingsBase $settings
     */
    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
        $this->api = new AlipayAPI($settings);
        $this->recurring = new AlipayRecurringAgreement($settings);
    }

    /**
     * Process subscription payment
     * 
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    public function processSubscription(PaymentInstance $paymentInstance)
    {
        try {
            $order = $paymentInstance->order;
            $transaction = $paymentInstance->transaction;
            $subscription = $paymentInstance->subscription;

            if (!$subscription) {
                return [
                    'status' => 'failed',
                    'message' => __('No subscription found.', 'wpkj-fluentcart-alipay-payment')
                ];
            }

            Logger::info('Processing Alipay Subscription Payment', [
                'order_id' => $order->id,
                'subscription_id' => $subscription->id,
                'order_type' => $order->type,
                'billing_interval' => $subscription->billing_interval
            ]);

            // 检查是否为续费且已有周期扣款协议
            if ($order->type === 'renewal' && $this->hasActiveAgreement($subscription)) {
                Logger::info('Using Recurring Agreement for Renewal', [
                    'subscription_id' => $subscription->id,
                    'agreement_no' => $subscription->vendor_subscription_id
                ]);

                return $this->processRenewalWithAgreement($subscription, $paymentInstance);
            }

            // 初始订阅：检查是否启用周期扣款
            if ($order->type !== 'renewal' && $this->recurring->isRecurringEnabled()) {
                Logger::info('Creating Recurring Agreement for Initial Subscription', [
                    'subscription_id' => $subscription->id
                ]);

                return $this->processInitialWithAgreement($subscription, $paymentInstance);
            }

            // 降级到手动续费模式
            Logger::info('Using Manual Renewal Mode', [
                'subscription_id' => $subscription->id,
                'reason' => $order->type === 'renewal' ? 'No active agreement' : 'Recurring not enabled'
            ]);

            return $this->processManualPayment($subscription, $paymentInstance);

        } catch (\Exception $e) {
            Logger::error('Subscription Processing Exception', [
                'order_id' => $order->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate vendor subscription ID
     * 
     * @param Subscription $subscription
     * @return string
     */
    private function generateVendorSubscriptionId(Subscription $subscription)
    {
        // Format: alipay_sub_{subscription_id}_{timestamp}
        return 'alipay_sub_' . $subscription->id . '_' . time();
    }

    /**
     * Prepare payment parameters
     * 
     * @param PaymentInstance $paymentInstance
     * @param int $amount Amount in cents
     * @param Subscription $subscription
     * @return array
     */
    private function preparePaymentParams(PaymentInstance $paymentInstance, $amount, Subscription $subscription)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        // Generate out_trade_no with timestamp
        $uuid = str_replace('-', '', $transaction->uuid);
        $timestamp = time();
        $outTradeNo = $uuid . '_' . $timestamp;

        // Store out_trade_no in transaction meta
        $transaction->meta = array_merge($transaction->meta ?? [], [
            'out_trade_no' => $outTradeNo,
            'is_subscription' => true,
            'subscription_id' => $subscription->id
        ]);
        $transaction->save();

        // Prepare subject and body
        $subject = $this->buildSubscriptionSubject($order, $subscription);
        $body = $this->buildSubscriptionBody($order, $subscription);

        // Build return and notify URLs
        $returnUrl = add_query_arg([
            'trx_hash' => $transaction->uuid,
            'fct_redirect' => 'yes'
        ], site_url('/'));

        // CRITICAL: Do NOT include 'method' parameter in notify_url!
        // Including method causes "suspected-attack" error in Face-to-Face payments
        // because Alipay uses 'method' internally in its callbacks
        $notifyUrl = add_query_arg([
            'fct_payment_listener' => '1'
        ], site_url('/'));

        return [
            'out_trade_no' => $outTradeNo,
            'total_amount' => Helper::toDecimal($amount),
            'subject' => $subject,
            'body' => $body,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'timeout_express' => AlipayConfig::PAYMENT_TIMEOUT_MINUTES . 'm',
            'product_code' => 'FAST_INSTANT_TRADE_PAY'
        ];
    }

    /**
     * Build subscription payment subject
     * 
     * @param object $order
     * @param Subscription $subscription
     * @return string
     */
    private function buildSubscriptionSubject($order, Subscription $subscription)
    {
        // Get order items
        $orderItems = $order->order_items ?? [];
        
        // Get product title from first order item
        $productTitle = '';
        
        if (!empty($orderItems)) {
            // Handle if order_items is a collection or array
            if (is_array($orderItems) && isset($orderItems[0])) {
                $firstItem = $orderItems[0];
                $productTitle = is_object($firstItem) ? ($firstItem->title ?? $firstItem->post_title ?? '') : ($firstItem['title'] ?? $firstItem['post_title'] ?? '');
            } else if (is_object($orderItems) && method_exists($orderItems, 'first')) {
                // If it's a Laravel Collection
                $firstItem = $orderItems->first();
                if ($firstItem) {
                    $productTitle = $firstItem->title ?? $firstItem->post_title ?? '';
                }
            }
        }
        
        // Fallback: try to get from subscription itself
        if (empty($productTitle) && $subscription->product_id) {
            $product = get_post($subscription->product_id);
            if ($product) {
                $productTitle = $product->post_title;
            }
        }
        
        // Final fallback
        if (empty($productTitle)) {
            $productTitle = __('Subscription', 'wpkj-fluentcart-alipay-payment');
        }
        
        // Build subject with subscription type
        if ($order->type === 'renewal') {
            $subject = sprintf(
                __('%s - Renewal', 'wpkj-fluentcart-alipay-payment'),
                $productTitle
            );
        } else {
            $subject = sprintf(
                __('%s - Subscription', 'wpkj-fluentcart-alipay-payment'),
                $productTitle
            );
        }
        
        // EDD plugin method: check byte length with strlen()
        // Truncate safely using mb_substr() to avoid breaking UTF-8
        if (strlen($subject) > AlipayConfig::MAX_SUBJECT_LENGTH) {
            $subject = mb_substr($subject, 0, floor(AlipayConfig::MAX_SUBJECT_LENGTH / 3));
            while (strlen($subject) > AlipayConfig::MAX_SUBJECT_LENGTH - 3) {
                $subject = mb_substr($subject, 0, mb_strlen($subject, 'UTF-8') - 1, 'UTF-8');
            }
            $subject .= '...';
        }

        return $subject;
    }

    /**
     * Build subscription payment body
     * 
     * @param object $order
     * @param Subscription $subscription
     * @return string
     */
    private function buildSubscriptionBody($order, Subscription $subscription)
    {
        $intervalText = $this->getIntervalText($subscription->billing_interval);
        
        $body = sprintf(
            __('Subscription payment - %s - Order #%s', 'wpkj-fluentcart-alipay-payment'),
            $intervalText,
            $order->id
        );

        // EDD plugin method: check byte length with strlen()
        // Truncate safely using mb_substr() to avoid breaking UTF-8
        if (strlen($body) > AlipayConfig::MAX_BODY_LENGTH) {
            $body = mb_substr($body, 0, floor(AlipayConfig::MAX_BODY_LENGTH / 3));
            while (strlen($body) > AlipayConfig::MAX_BODY_LENGTH - 3) {
                $body = mb_substr($body, 0, mb_strlen($body, 'UTF-8') - 1, 'UTF-8');
            }
            $body .= '...';
        }

        return $body;
    }

    /**
     * Get interval text for display
     * 
     * @param string $interval
     * @return string
     */
    private function getIntervalText($interval)
    {
        $intervals = [
            'day' => __('Daily', 'wpkj-fluentcart-alipay-payment'),
            'week' => __('Weekly', 'wpkj-fluentcart-alipay-payment'),
            'month' => __('Monthly', 'wpkj-fluentcart-alipay-payment'),
            'year' => __('Yearly', 'wpkj-fluentcart-alipay-payment')
        ];

        return $intervals[$interval] ?? ucfirst($interval);
    }

    /**
     * Process payment by client type
     * 
     * @param string $clientType
     * @param array $params
     * @param PaymentInstance $paymentInstance
     * @return array|\WP_Error
     */
    private function processPaymentByClientType($clientType, $params, PaymentInstance $paymentInstance)
    {
        switch ($clientType) {
            case 'mobile':
                return $this->processMobilePayment($params, $paymentInstance);
            
            case 'pc':
                return $this->processPCPayment($params, $paymentInstance);
            
            default:
                return $this->processPCPayment($params, $paymentInstance);
        }
    }

    /**
     * Process PC payment
     * 
     * @param array $params
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    private function processPCPayment($params, PaymentInstance $paymentInstance)
    {
        // Check if Face-to-Face is enabled for PC
        $enableF2FPC = $this->settings->get('enable_face_to_face_pc');
        
        if ($enableF2FPC === 'yes') {
            return $this->processFaceToFacePayment($params, $paymentInstance);
        }

        // Standard PC web payment
        $result = $this->api->createPagePayment($params);

        if (is_wp_error($result)) {
            return $result;
        }

        Logger::info('Subscription PC Payment Created', [
            'transaction_uuid' => $paymentInstance->transaction->uuid,
            'subscription_id' => $paymentInstance->subscription->id
        ]);

        return [
            'status' => 'processing',
            'nextAction' => 'redirect',
            'actionName' => 'browser_redirect',
            'redirect_url' => $result['redirect_url'],
            'message' => __('Redirecting to Alipay...', 'wpkj-fluentcart-alipay-payment')
        ];
    }

    /**
     * Process mobile payment
     * 
     * @param array $params
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    private function processMobilePayment($params, PaymentInstance $paymentInstance)
    {
        $result = $this->api->createWapPayment($params);

        if (is_wp_error($result)) {
            return $result;
        }

        Logger::info('Subscription Mobile Payment Created', [
            'transaction_uuid' => $paymentInstance->transaction->uuid,
            'subscription_id' => $paymentInstance->subscription->id
        ]);

        return [
            'status' => 'processing',
            'nextAction' => 'redirect',
            'actionName' => 'browser_redirect',
            'redirect_url' => $result['redirect_url'],
            'message' => __('Redirecting to Alipay...', 'wpkj-fluentcart-alipay-payment')
        ];
    }

    /**
     * Process Face-to-Face payment (QR code)
     * 
     * @param array $params
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    private function processFaceToFacePayment($params, PaymentInstance $paymentInstance)
    {
        $transaction = $paymentInstance->transaction;
        $order = $paymentInstance->order;
        
        // Remove return_url for F2F payment (not used)
        unset($params['return_url']);
        // Remove product_code for F2F payment (not needed for precreate)
        unset($params['product_code']);

        $result = $this->api->createFaceToFacePayment($params);

        if (is_wp_error($result)) {
            return $result;
        }

        // Save QR code and payment info to transaction meta
        $transaction->meta = array_merge($transaction->meta ?? [], [
            'qr_code' => $result['qr_code'],
            'payment_method_type' => 'face_to_face',
            'out_trade_no' => $params['out_trade_no'],
            'is_subscription' => true,
            'subscription_id' => $paymentInstance->subscription->id
        ]);
        $transaction->save();

        Logger::info('Subscription Face-to-Face Payment Created', [
            'transaction_uuid' => $transaction->uuid,
            'subscription_id' => $paymentInstance->subscription->id,
            'qr_code' => $result['qr_code']
        ]);

        // Build direct QR code page URL (same as single payment)
        $qrPageUrl = home_url('/');
        $qrPageUrl = add_query_arg([
            'fluent-cart' => 'alipay_f2f_payment',
            'order_hash' => $order->uuid,
            'qr_code' => base64_encode($result['qr_code']),
            'trx_uuid' => $transaction->uuid
        ], $qrPageUrl);

        Logger::info('Subscription F2F Payment Redirect URL', [
            'redirect_to' => $qrPageUrl,
            'order_uuid' => $order->uuid,
            'transaction_uuid' => $transaction->uuid,
            'has_qr_code' => !empty($result['qr_code'])
        ]);

        // Return the same format as single payment
        return [
            'status' => 'success',
            'message' => __('Redirecting to payment page...', 'wpkj-fluentcart-alipay-payment'),
            'redirect_to' => $qrPageUrl
        ];
    }

    /**
     * 检查订阅是否有活跃的周期扣款协议
     * 
     * @param Subscription $subscription
     * @return bool
     */
    private function hasActiveAgreement(Subscription $subscription)
    {
        $agreementNo = $subscription->vendor_subscription_id;
        
        if (empty($agreementNo)) {
            return false;
        }

        $agreementStatus = $subscription->getMeta('alipay_agreement_status');
        
        return $agreementStatus === 'active';
    }

    /**
     * 使用周期扣款协议处理续费
     * 
     * @param Subscription $subscription
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    private function processRenewalWithAgreement(Subscription $subscription, PaymentInstance $paymentInstance)
    {
        $amount = $subscription->getCurrentRenewalAmount();
        
        $orderData = [
            'transaction_uuid' => $paymentInstance->transaction->uuid,
            'order_id' => $paymentInstance->order->id
        ];

        // 执行协议代扣
        $result = $this->recurring->executeAgreementPay($subscription, $amount, $orderData);

        if (is_wp_error($result)) {
            // 代扣失败，降级到手动支付
            Logger::warning('Agreement Pay Failed, Fallback to Manual Payment', [
                'subscription_id' => $subscription->id,
                'error' => $result->get_error_message()
            ]);

            return $this->processManualPayment($subscription, $paymentInstance);
        }

        // 代扣成功，直接返回成功结果
        return [
            'status' => 'success',
            'nextAction' => 'none',
            'message' => __('Renewal payment completed automatically.', 'wpkj-fluentcart-alipay-payment'),
            'payment_args' => [
                'auto_renewed' => true,
                'trade_no' => $result['trade_no'] ?? ''
            ]
        ];
    }

    /**
     * 初始订阅使用周期扣款协议
     * 
     * @param Subscription $subscription
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    private function processInitialWithAgreement(Subscription $subscription, PaymentInstance $paymentInstance)
    {
        $orderData = [
            'transaction_uuid' => $paymentInstance->transaction->uuid,
            'order_id' => $paymentInstance->order->id
        ];

        // 创建签约页面
        $result = $this->recurring->createAgreementSign($subscription, $orderData);

        if (is_wp_error($result)) {
            // 签约失败，降级到手动支付
            Logger::warning('Agreement Sign Creation Failed, Fallback to Manual Payment', [
                'subscription_id' => $subscription->id,
                'error' => $result->get_error_message()
            ]);

            return $this->processManualPayment($subscription, $paymentInstance);
        }

        // 跳转到签约页面
        return [
            'status' => 'processing',
            'nextAction' => 'redirect',
            'actionName' => 'browser_redirect',
            'redirect_url' => $result['redirect_url'],
            'message' => __('Redirecting to Alipay for recurring agreement...', 'wpkj-fluentcart-alipay-payment'),
            'payment_args' => [
                'agreement_mode' => true,
                'external_agreement_no' => $result['external_agreement_no']
            ]
        ];
    }

    /**
     * 手动支付模式（备选方案）
     * 
     * @param Subscription $subscription
     * @param PaymentInstance $paymentInstance
     * @return array
     */
    private function processManualPayment(Subscription $subscription, PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        // Determine payment amount based on order type
        if ($order->type === 'renewal') {
            // Renewal payment: only charge recurring amount
            $paymentAmount = $subscription->getCurrentRenewalAmount();
        } else {
            // Initial payment: signup fee + first period (may include trial)
            $paymentAmount = (int)$subscription->signup_fee + (int)$subscription->recurring_total;
            
            // If there's a trial period, first charge is only signup fee
            if ($subscription->trial_days > 0) {
                $paymentAmount = (int)$subscription->signup_fee;
            }
        }

        // Ensure minimum amount
        if ($paymentAmount < AlipayConfig::MIN_PAYMENT_AMOUNT_CENTS) {
            $paymentAmount = $transaction->total;
        }

        Logger::info('Manual Payment Amount Calculated', [
            'order_type' => $order->type,
            'amount' => $paymentAmount
        ]);

        // Generate vendor subscription ID (for local tracking)
        if (empty($subscription->vendor_subscription_id)) {
            $vendorSubscriptionId = $this->generateVendorSubscriptionId($subscription);
            $subscription->update([
                'vendor_subscription_id' => $vendorSubscriptionId,
                'current_payment_method' => 'alipay'
            ]);
        }

        // Create payment parameters
        $paymentParams = $this->preparePaymentParams($paymentInstance, $paymentAmount, $subscription);

        // Process payment based on client type
        $clientType = ClientDetector::detect();
        return $this->processPaymentByClientType($clientType, $paymentParams, $paymentInstance);
    }
}
