<?php

namespace WPKJFluentCart\Alipay\Webhook;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Return Handler
 * 
 * Handles return URL callback and actively queries payment status
 */
class ReturnHandler
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
     * Handle return URL callback
     * 
     * This is triggered when user returns from Alipay after payment
     * We actively query the payment status instead of waiting for notification
     * 
     * Note: Alipay appends its own parameters to return_url, including method=alipay.trade.page.pay.return
     * which overwrites our method=alipay parameter. So we cannot rely on checking method=alipay.
     * 
     * @return void
     */
    public function handleReturn()
    {
        // Get parameters - note we don't check 'method' anymore because Alipay overwrites it
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Alipay return URL verified by signature
        $trxHash = isset($_GET['trx_hash']) ? sanitize_text_field(wp_unslash($_GET['trx_hash'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Alipay return URL verified by signature
        $redirect = isset($_GET['fct_redirect']) ? sanitize_text_field(wp_unslash($_GET['fct_redirect'])) : '';

        if ($redirect !== 'yes' || empty($trxHash)) {
            Logger::warning('Invalid return parameters', [
                'trx_hash' => $trxHash,
                'fct_redirect' => $redirect
            ]);
            return;
        }

        Logger::info('Return URL triggered', ['trx_hash' => $trxHash]);

        // Find transaction
        $transaction = OrderTransaction::query()
            ->where('uuid', $trxHash)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if (!$transaction) {
            Logger::error('Transaction not found', ['trx_hash' => $trxHash]);
            return;
        }
        
        // ✅ Check if this transaction belongs to Alipay
        if ($transaction->payment_method !== 'alipay') {
            Logger::info('Skipping Alipay return handler - different payment method', [
                'trx_hash' => $trxHash,
                'payment_method' => $transaction->payment_method ?? 'unknown'
            ]);
            return;
        }

        // If already succeeded, skip query
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            Logger::info('Transaction already completed', ['trx_hash' => $trxHash]);
            return;
        }

        // Query payment status from Alipay
        $this->queryAndUpdatePaymentStatus($transaction);
    }

    /**
     * Query payment status from Alipay and update order
     * 
     * @param OrderTransaction $transaction Transaction instance
     * @return void
     */
    private function queryAndUpdatePaymentStatus($transaction)
    {
        try {
            // CRITICAL: Retrieve out_trade_no from transaction meta
            // DO NOT regenerate because it contains creation timestamp
            $outTradeNo = $transaction->meta['out_trade_no'] ?? null;
            
            // Fallback for old transactions without stored out_trade_no
            if (empty($outTradeNo)) {
                Logger::warning('Missing out_trade_no in transaction meta, using fallback', [
                    'transaction_uuid' => $transaction->uuid
                ]);
                // Use old format (without timestamp) for backward compatibility
                $outTradeNo = str_replace('-', '', $transaction->uuid);
            }

            // Query trade status
            $result = $this->api->queryTrade($outTradeNo);

            if (is_wp_error($result)) {
                Logger::error('Query trade API error', [
                    'transaction_uuid' => $transaction->uuid,
                    'error_code' => $result->get_error_code(),
                    'error_message' => $result->get_error_message()
                ]);
                return;
            }

            $tradeStatus = $result['trade_status'] ?? '';

            // Handle based on trade status
            switch ($tradeStatus) {
                case 'TRADE_SUCCESS':
                case 'TRADE_FINISHED':
                    Logger::info('Payment successful', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_status' => $tradeStatus,
                        'trade_no' => $result['trade_no'] ?? ''
                    ]);
                    
                    // Payment successful, update order
                    $this->processor->confirmPaymentSuccess($transaction, $result);
                    break;

                case 'WAIT_BUYER_PAY':
                    Logger::info('Payment pending', [
                        'transaction_uuid' => $transaction->uuid
                    ]);
                    break;

                case 'TRADE_CLOSED':
                    Logger::info('Payment closed', [
                        'transaction_uuid' => $transaction->uuid
                    ]);
                    
                    // Payment failed or cancelled
                    $this->processor->processFailedPayment($transaction, [
                        'reason' => 'Trade closed'
                    ]);
                    break;

                default:
                    Logger::warning('Unknown trade status', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_status' => $tradeStatus
                    ]);
                    break;
            }

        } catch (\Exception $e) {
            Logger::error('Query payment status exception', [
                'transaction_uuid' => $transaction->uuid,
                'exception_message' => $e->getMessage()
            ]);
        }
    }
}
