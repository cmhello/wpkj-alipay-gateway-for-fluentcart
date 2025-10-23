<?php

namespace WPKJFluentCart\Alipay\Webhook;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Helpers\Status;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Services\SubscriptionService;
use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;
use WPKJFluentCart\Alipay\Subscription\AlipayRecurringAgreement;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Notify Handler
 * 
 * Handles Alipay asynchronous notifications (webhooks)
 */
class NotifyHandler
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
     * Processor instance
     * 
     * @var PaymentProcessor
     */
    private $processor;

    /**
     * Recurring agreement instance
     * 
     * @var AlipayRecurringAgreement
     */
    private $recurring;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->settings = new AlipaySettingsBase();
        $this->api = new AlipayAPI($this->settings);
        $this->processor = new PaymentProcessor($this->settings);
        $this->recurring = new AlipayRecurringAgreement($this->settings);
    }

    /**
     * Process asynchronous notification
     * 
     * @return void
     */
    public function processNotify()
    {
        // Get POST data
        $data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Alipay webhook verification handled by signature verification

        if (empty($data)) {
            Logger::error('Notify Processing', 'Empty notification data');
            $this->sendResponse('fail');
            return;
        }

        // Sanitize data
        $data = Helper::sanitizeResponseData($data);

        Logger::info('Notify Received', $data);

        // Check if this is an agreement sign callback
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Action validated below
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
        if ($action === 'agreement') {
            $this->processAgreementNotify($data);
            return;
        }

        // Check for replay attack using notify_id
        $notifyId = $data['notify_id'] ?? '';
        if (!empty($notifyId)) {
            $cacheKey = 'alipay_notify_processed_' . md5($notifyId);
            
            if (get_transient($cacheKey)) {
                Logger::warning('Duplicate Notification Ignored (Replay Attack Prevention)', [
                    'notify_id' => $notifyId,
                    'out_trade_no' => $data['out_trade_no'] ?? ''
                ]);
                // Return success to prevent Alipay from retrying
                $this->sendResponse('success');
                return;
            }
            
            // Mark this notification as processed (use config TTL)
            set_transient($cacheKey, true, AlipayConfig::NOTIFY_DEDUP_TTL);
        }

        // Verify signature
        if (!$this->verifyNotification($data)) {
            Logger::error('Notify Signature Verification Failed', $data);
            $this->sendResponse('fail');
            return;
        }

        // Handle notification based on trade status
        $tradeStatus = $data['trade_status'] ?? '';

        switch ($tradeStatus) {
            case 'TRADE_SUCCESS':
            case 'TRADE_FINISHED':
                $this->handlePaymentSuccess($data);
                break;

            case 'TRADE_CLOSED':
                $this->handlePaymentFailure($data);
                break;

            default:
                Logger::info('Notify Ignored', [
                    'trade_status' => $tradeStatus,
                    'out_trade_no' => $data['out_trade_no'] ?? ''
                ]);
                break;
        }

        // Send success response to Alipay
        $this->sendResponse('success');
    }

    /**
     * Handle payment success notification
     * 
     * @param array $data Notification data
     * @return void
     */
    public function handlePaymentSuccess($data)
    {
        $outTradeNo = $data['out_trade_no'] ?? '';

        if (empty($outTradeNo)) {
            Logger::error('Invalid Notify Data', 'Missing out_trade_no');
            return;
        }

        // Find transaction by out_trade_no (which is the transaction UUID without dashes)
        $transactionUuid = $this->parseOutTradeNo($outTradeNo);
        
        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionUuid)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if (!$transaction) {
            Logger::error('Transaction Not Found', [
                'out_trade_no' => $outTradeNo,
                'transaction_uuid' => $transactionUuid
            ]);
            return;
        }
        
        // ✅ Check if this transaction belongs to Alipay
        if ($transaction->payment_method !== 'alipay') {
            Logger::warning('Skipping Alipay notify handler - different payment method', [
                'transaction_uuid' => $transaction->uuid,
                'payment_method' => $transaction->payment_method ?? 'unknown'
            ]);
            return;
        }

        // Check if this is a subscription payment
        if (SubscriptionService::isSubscriptionTransaction($transaction)) {
            Logger::info('Processing Subscription Payment Success', [
                'transaction_uuid' => $transaction->uuid,
                'trade_no' => $data['trade_no'] ?? ''
            ]);
            
            SubscriptionService::handleSubscriptionPaymentSuccess($transaction, $data, 'webhook');
        } else {
            // Regular payment processing
            $this->processor->confirmPaymentSuccess($transaction, $data);
        }
    }

    /**
     * Handle payment failure notification
     * 
     * @param array $data Notification data
     * @return void
     */
    public function handlePaymentFailure($data)
    {
        $outTradeNo = $data['out_trade_no'] ?? '';

        if (empty($outTradeNo)) {
            Logger::error('Invalid Notify Data', 'Missing out_trade_no');
            return;
        }

        // Find transaction
        $transactionUuid = $this->parseOutTradeNo($outTradeNo);
        
        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionUuid)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if (!$transaction) {
            Logger::error('Transaction Not Found', [
                'out_trade_no' => $outTradeNo,
                'transaction_uuid' => $transactionUuid
            ]);
            return;
        }
        
        // ✅ Check if this transaction belongs to Alipay
        if ($transaction->payment_method !== 'alipay') {
            Logger::warning('Skipping Alipay notify handler - different payment method', [
                'transaction_uuid' => $transaction->uuid,
                'payment_method' => $transaction->payment_method ?? 'unknown'
            ]);
            return;
        }

        // Process failed payment
        $this->processor->processFailedPayment($transaction, [
            'reason' => $data['trade_status'] ?? 'Trade closed'
        ]);
    }

    /**
     * Verify notification signature
     * 
     * @param array $data Notification data
     * @return bool Verification result
     */
    private function verifyNotification($data)
    {
        // Check if verification is disabled (for testing only)
        if ($this->settings->get('notify_url_verification') === 'no') {
            Logger::warning('Signature Verification Disabled', 'This should only be used for testing');
            return true;
        }

        return $this->api->verifySignature($data);
    }

    /**
     * Parse out_trade_no to get transaction UUID
     * 
     * Supports two formats:
     * 1. New format (with timestamp): {uuid_without_dashes}_{timestamp_microseconds}
     *    Example: 6a3f5c2e7b1d4a9e8f0c1d2e3f4a5b6c_17050123456789012
     * 2. Old format (without timestamp): {uuid_without_dashes}
     *    Example: 6a3f5c2e7b1d4a9e8f0c1d2e3f4a5b6c
     * 
     * @param string $outTradeNo Out trade number
     * @return string Transaction UUID
     */
    private function parseOutTradeNo($outTradeNo)
    {
        // Check if new format (contains underscore)
        if (strpos($outTradeNo, '_') !== false) {
            // Extract UUID part before underscore
            $parts = explode('_', $outTradeNo);
            $uuidPart = $parts[0];
            
            // Restore UUID format with dashes
            if (strlen($uuidPart) === 32) {
                return substr($uuidPart, 0, 8) . '-' .
                       substr($uuidPart, 8, 4) . '-' .
                       substr($uuidPart, 12, 4) . '-' .
                       substr($uuidPart, 16, 4) . '-' .
                       substr($uuidPart, 20);
            }
        }
        
        // Old format (32 chars without timestamp)
        if (strlen($outTradeNo) === 32) {
            return substr($outTradeNo, 0, 8) . '-' .
                   substr($outTradeNo, 8, 4) . '-' .
                   substr($outTradeNo, 12, 4) . '-' .
                   substr($outTradeNo, 16, 4) . '-' .
                   substr($outTradeNo, 20);
        }

        // Unknown format, log warning and return as-is
        Logger::warning('Unknown out_trade_no Format', [
            'out_trade_no' => $outTradeNo,
            'length' => strlen($outTradeNo)
        ]);
        
        return $outTradeNo;
    }

    /**
     * Send response to Alipay
     * 
     * @param string $result Response result (success/fail)
     * @return void
     */
    private function sendResponse($result)
    {
        // Alipay expects simple text response
        // Only 'success' or 'fail' are valid responses - no escaping needed for these fixed strings
        echo esc_html($result);
        exit;
    }

    /**
     * Process agreement sign notification
     * 
     * @param array $data
     * @return void
     */
    private function processAgreementNotify($data)
    {
        // Verify signature
        if (!$this->verifyNotification($data)) {
            Logger::error('Agreement Notify Signature Verification Failed', $data);
            $this->sendResponse('fail');
            return;
        }

        // Process agreement callback
        $result = $this->recurring->handleAgreementCallback($data);

        if ($result) {
            $this->sendResponse('success');
        } else {
            $this->sendResponse('fail');
        }
    }
}
