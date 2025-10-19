<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Payment Status Checker
 * 
 * Handles AJAX requests to check face-to-face payment status
 */
class PaymentStatusChecker
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
     * Constructor
     */
    public function __construct()
    {
        $this->settings = new AlipaySettingsBase();
        $this->api = new AlipayAPI($this->settings);
    }

    /**
     * Register AJAX hooks
     * 
     * @return void
     */
    public function register()
    {
        add_action('wp_ajax_wpkj_alipay_check_payment_status', [$this, 'checkPaymentStatus']);
        add_action('wp_ajax_nopriv_wpkj_alipay_check_payment_status', [$this, 'checkPaymentStatus']);
    }

    /**
     * Check payment status via AJAX
     * 
     * @return void
     */
    public function checkPaymentStatus()
    {
        try {
            $transactionUuid = sanitize_text_field($_POST['transaction_uuid'] ?? '');

            if (empty($transactionUuid)) {
                wp_send_json_error([
                    'message' => __('Invalid transaction ID', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionUuid)
                ->first();

            if (!$transaction) {
                wp_send_json_error([
                    'message' => __('Transaction not found', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                $order = Order::find($transaction->order_id);
                
                wp_send_json_success([
                    'status' => 'paid',
                    'message' => __('Payment completed successfully', 'wpkj-fluentcart-alipay-payment'),
                    'redirect_url' => $this->getReceiptUrl($order)
                ]);
                return;
            }

            $outTradeNo = Helper::generateOutTradeNo($transaction->uuid);
            $result = $this->api->queryTrade($outTradeNo);

            if (is_wp_error($result)) {
                wp_send_json_success([
                    'status' => 'waiting',
                    'message' => __('Waiting for payment', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            if (isset($result['trade_status'])) {
                $tradeStatus = $result['trade_status'];

                if ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED') {
                    wp_send_json_success([
                        'status' => 'paid',
                        'message' => __('Payment completed', 'wpkj-fluentcart-alipay-payment'),
                        'needs_verification' => true
                    ]);
                    return;
                } elseif ($tradeStatus === 'TRADE_CLOSED') {
                    wp_send_json_success([
                        'status' => 'failed',
                        'message' => __('Payment cancelled or closed', 'wpkj-fluentcart-alipay-payment')
                    ]);
                    return;
                }
            }

            wp_send_json_success([
                'status' => 'waiting',
                'message' => __('Waiting for payment', 'wpkj-fluentcart-alipay-payment')
            ]);

        } catch (\Exception $e) {
            Logger::error('Payment Status Check Error', [
                'message' => $e->getMessage(),
                'transaction_uuid' => $transactionUuid ?? ''
            ]);

            wp_send_json_error([
                'message' => __('Failed to check payment status', 'wpkj-fluentcart-alipay-payment')
            ]);
        }
    }

    /**
     * Get receipt URL for order
     * 
     * @param Order $order Order instance
     * @return string Receipt URL
     */
    private function getReceiptUrl($order)
    {
        $storeSettings = new \FluentCart\Api\StoreSettings();
        $receiptPage = $storeSettings->getReceiptPage();

        if (empty($receiptPage)) {
            $receiptPage = home_url('/?fluent-cart=receipt');
        }

        return add_query_arg([
            'order_uuid' => $order->uuid
        ], $receiptPage);
    }
}
