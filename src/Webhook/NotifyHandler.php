<?php

namespace WPKJFluentCart\Alipay\Webhook;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;
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
     * Constructor
     */
    public function __construct()
    {
        $this->settings = new AlipaySettingsBase();
        $this->api = new AlipayAPI($this->settings);
        $this->processor = new PaymentProcessor($this->settings);
    }

    /**
     * Process asynchronous notification
     * 
     * @return void
     */
    public function processNotify()
    {
        // Get POST data
        $data = $_POST;

        if (empty($data)) {
            Logger::error('Notify Processing', 'Empty notification data');
            $this->sendResponse('fail');
            return;
        }

        // Sanitize data
        $data = Helper::sanitizeResponseData($data);

        Logger::info('Notify Received', $data);

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
            
            // Mark this notification as processed (24 hours)
            set_transient($cacheKey, true, DAY_IN_SECONDS);
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

        // Process payment confirmation
        $this->processor->confirmPaymentSuccess($transaction, $data);
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
     * @param string $outTradeNo Out trade number
     * @return string Transaction UUID
     */
    private function parseOutTradeNo($outTradeNo)
    {
        // out_trade_no is UUID without dashes, restore the dashes
        // Format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        if (strlen($outTradeNo) === 32) {
            return substr($outTradeNo, 0, 8) . '-' .
                   substr($outTradeNo, 8, 4) . '-' .
                   substr($outTradeNo, 12, 4) . '-' .
                   substr($outTradeNo, 16, 4) . '-' .
                   substr($outTradeNo, 20);
        }

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
        echo $result;
        exit;
    }
}
