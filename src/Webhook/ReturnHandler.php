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
        Logger::info('=== ReturnHandler::handleReturn() START ===', [
            'timestamp' => date('Y-m-d H:i:s'),
            'all_get_params' => $_GET
        ]);
        
        // Get parameters - note we don't check 'method' anymore because Alipay overwrites it
        $trxHash = isset($_GET['trx_hash']) ? sanitize_text_field($_GET['trx_hash']) : '';
        $redirect = isset($_GET['fct_redirect']) ? sanitize_text_field($_GET['fct_redirect']) : '';

        Logger::info('Step 4: Parameter Validation', [
            'trx_hash' => $trxHash,
            'trx_hash_empty' => empty($trxHash),
            'fct_redirect' => $redirect,
            'fct_redirect_is_yes' => ($redirect === 'yes')
        ]);

        if ($redirect !== 'yes' || empty($trxHash)) {
            Logger::warning('Step 4 FAILED: Invalid parameters', [
                'redirect_check' => $redirect !== 'yes' ? 'FAILED' : 'PASSED',
                'trxHash_check' => empty($trxHash) ? 'FAILED' : 'PASSED'
            ]);
            return;
        }

        Logger::info('Step 5: Return URL Triggered - Parameters Valid', [
            'trx_hash' => $trxHash,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'direct',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Find transaction
        Logger::info('Step 6: Querying Transaction from Database', [
            'trx_hash' => $trxHash
        ]);
        
        $transaction = OrderTransaction::query()
            ->where('uuid', $trxHash)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if (!$transaction) {
            Logger::error('Step 6 FAILED: Transaction Not Found', [
                'trx_hash' => $trxHash,
                'searched_in_table' => 'wp_fct_order_transactions',
                'search_conditions' => [
                    'uuid' => $trxHash,
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE
                ]
            ]);
            return;
        }

        Logger::info('Step 7: Transaction Found in Database', [
            'transaction_id' => $transaction->id,
            'transaction_uuid' => $transaction->uuid,
            'order_id' => $transaction->order_id,
            'current_status' => $transaction->status,
            'payment_method' => $transaction->payment_method,
            'total' => $transaction->total,
            'created_at' => $transaction->created_at
        ]);

        // If already succeeded, skip query
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            Logger::info('Step 8: Transaction Already Completed - Skipping Query', [
                'trx_hash' => $trxHash,
                'status' => $transaction->status
            ]);
            return;
        }

        Logger::info('Step 8: Transaction Pending - Will Query Alipay', [
            'trx_hash' => $trxHash,
            'current_status' => $transaction->status
        ]);

        // Query payment status from Alipay
        $this->queryAndUpdatePaymentStatus($transaction);
        
        Logger::info('=== ReturnHandler::handleReturn() END ===', [
            'timestamp' => date('Y-m-d H:i:s')
        ]);
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
            Logger::info('Step 9: Starting Payment Status Query', [
                'transaction_uuid' => $transaction->uuid,
                'transaction_id' => $transaction->id
            ]);
            
            // Generate out_trade_no
            $outTradeNo = Helper::generateOutTradeNo($transaction->uuid);

            Logger::info('Step 10: Generated out_trade_no', [
                'transaction_uuid' => $transaction->uuid,
                'out_trade_no' => $outTradeNo
            ]);

            // Query trade status
            Logger::info('Step 11: Calling AlipayAPI::queryTrade()', [
                'out_trade_no' => $outTradeNo
            ]);
            
            $result = $this->api->queryTrade($outTradeNo);

            if (is_wp_error($result)) {
                Logger::error('Step 11 FAILED: Query Trade API Error', [
                    'transaction_uuid' => $transaction->uuid,
                    'error_code' => $result->get_error_code(),
                    'error_message' => $result->get_error_message()
                ]);
                return;
            }

            Logger::info('Step 12: AlipayAPI::queryTrade() Response Received', [
                'transaction_uuid' => $transaction->uuid,
                'response_keys' => array_keys($result),
                'full_response' => $result
            ]);

            $tradeStatus = $result['trade_status'] ?? '';

            Logger::info('Step 13: Processing Trade Status', [
                'transaction_uuid' => $transaction->uuid,
                'trade_status' => $tradeStatus,
                'trade_no' => $result['trade_no'] ?? 'not set',
                'total_amount' => $result['total_amount'] ?? 'not set',
                'buyer_logon_id' => $result['buyer_logon_id'] ?? 'not set'
            ]);

            // Handle based on trade status
            switch ($tradeStatus) {
                case 'TRADE_SUCCESS':
                case 'TRADE_FINISHED':
                    Logger::info('Step 14: Payment SUCCESS - Calling confirmPaymentSuccess()', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_status' => $tradeStatus
                    ]);
                    
                    // Payment successful, update order
                    $this->processor->confirmPaymentSuccess($transaction, $result);
                    
                    Logger::info('Step 15: confirmPaymentSuccess() Completed', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_no' => $result['trade_no'] ?? ''
                    ]);
                    break;

                case 'WAIT_BUYER_PAY':
                    Logger::info('Step 14: Payment PENDING - Still Waiting', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_status' => $tradeStatus
                    ]);
                    break;

                case 'TRADE_CLOSED':
                    Logger::info('Step 14: Payment FAILED/CLOSED', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_status' => $tradeStatus
                    ]);
                    
                    // Payment failed or cancelled
                    $this->processor->processFailedPayment($transaction, [
                        'reason' => 'Trade closed'
                    ]);
                    break;

                default:
                    Logger::warning('Step 14: Unknown Trade Status', [
                        'transaction_uuid' => $transaction->uuid,
                        'trade_status' => $tradeStatus,
                        'full_response' => $result
                    ]);
                    break;
            }

        } catch (\Exception $e) {
            Logger::error('Query Payment Status Exception', [
                'transaction_uuid' => $transaction->uuid,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString()
            ]);
        }
    }
}
