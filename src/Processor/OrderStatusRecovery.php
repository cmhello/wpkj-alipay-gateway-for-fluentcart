<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Order Status Recovery
 * 
 * Provides manual payment status check for stuck orders
 */
class OrderStatusRecovery
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
     * Payment processor instance
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
     * Register hooks
     * 
     * @return void
     */
    public function register()
    {
        // Add AJAX handler for manual status check
        add_action('wp_ajax_wpkj_alipay_manual_check_order_status', [$this, 'manualCheckOrderStatus']);
        add_action('wp_ajax_nopriv_wpkj_alipay_manual_check_order_status', [$this, 'manualCheckOrderStatus']);
        
        // Add button to order receipt page
        add_action('wp_footer', [$this, 'addCheckButtonScript']);
    }

    /**
     * Manual check order status via AJAX
     * 
     * @return void
     */
    public function manualCheckOrderStatus()
    {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpkj_alipay_manual_check')) {
                wp_send_json_error([
                    'message' => __('Security verification failed', 'wpkj-fluentcart-alipay-payment')
                ], 403);
                return;
            }

            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');

            if (empty($orderUuid)) {
                wp_send_json_error([
                    'message' => __('Invalid order ID', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            $order = Order::query()->where('uuid', $orderUuid)->first();
            
            if (!$order) {
                wp_send_json_error([
                    'message' => __('Order not found', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            // Find latest Alipay transaction
            $transaction = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('payment_method', 'alipay')
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->orderBy('id', 'DESC')
                ->first();

            if (!$transaction) {
                wp_send_json_error([
                    'message' => __('No Alipay transaction found', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            // If already succeeded, no need to check
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                wp_send_json_success([
                    'status' => 'already_paid',
                    'message' => __('Payment already completed', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            Logger::info('Manual Status Check Started', [
                'order_uuid' => $orderUuid,
                'transaction_uuid' => $transaction->uuid,
                'current_status' => $transaction->status
            ]);

            // Get out_trade_no from transaction meta
            $outTradeNo = $transaction->meta['out_trade_no'] ?? null;
            
            if (empty($outTradeNo)) {
                Logger::warning('Missing out_trade_no in Manual Check', [
                    'transaction_uuid' => $transaction->uuid
                ]);
                wp_send_json_error([
                    'message' => __('Transaction data incomplete', 'wpkj-fluentcart-alipay-payment')
                ]);
                return;
            }

            // Query trade status from Alipay
            $result = $this->api->queryTrade($outTradeNo);

            if (is_wp_error($result)) {
                Logger::error('Manual Check Query Error', [
                    'error_code' => $result->get_error_code(),
                    'error_message' => $result->get_error_message()
                ]);
                
                wp_send_json_error([
                    'message' => $result->get_error_message()
                ]);
                return;
            }

            $tradeStatus = $result['trade_status'] ?? '';

            Logger::info('Manual Check Query Result', [
                'order_uuid' => $orderUuid,
                'trade_status' => $tradeStatus,
                'trade_no' => $result['trade_no'] ?? ''
            ]);

            // Handle based on trade status
            switch ($tradeStatus) {
                case 'TRADE_SUCCESS':
                case 'TRADE_FINISHED':
                    // Process payment success
                    $this->processor->confirmPaymentSuccess($transaction, $result);
                    
                    Logger::info('Manual Check - Payment Confirmed', [
                        'order_uuid' => $orderUuid,
                        'trade_no' => $result['trade_no'] ?? ''
                    ]);
                    
                    wp_send_json_success([
                        'status' => 'paid',
                        'message' => __('Payment confirmed successfully! Page will refresh...', 'wpkj-fluentcart-alipay-payment'),
                        'should_refresh' => true
                    ]);
                    break;

                case 'WAIT_BUYER_PAY':
                    wp_send_json_success([
                        'status' => 'pending',
                        'message' => __('Payment not completed yet', 'wpkj-fluentcart-alipay-payment')
                    ]);
                    break;

                case 'TRADE_CLOSED':
                    wp_send_json_success([
                        'status' => 'failed',
                        'message' => __('Payment has been closed', 'wpkj-fluentcart-alipay-payment')
                    ]);
                    break;

                default:
                    wp_send_json_error([
                        'message' => __('Unknown payment status', 'wpkj-fluentcart-alipay-payment')
                    ]);
                    break;
            }

        } catch (\Exception $e) {
            Logger::error('Manual Check Exception', [
                'message' => $e->getMessage(),
                'order_uuid' => $orderUuid ?? ''
            ]);

            wp_send_json_error([
                'message' => __('Failed to check payment status', 'wpkj-fluentcart-alipay-payment')
            ]);
        }
    }

    /**
     * Add check button script to order receipt page
     * 
     * @return void
     */
    public function addCheckButtonScript()
    {
        // Only on receipt page with pending order
        if (!isset($_GET['order_hash'])) {
            return;
        }

        $orderHash = sanitize_text_field($_GET['order_hash']);
        $order = Order::query()->where('uuid', $orderHash)->first();
        
        if (!$order) {
            return;
        }

        // Only show for pending Alipay orders
        if ($order->payment_status === Status::PAYMENT_PAID) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('payment_method', 'alipay')
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$transaction) {
            return;
        }

        ?>
        <script>
        jQuery(document).ready(function($) {
            // Add check button to page
            var buttonHtml = '<div style="text-align: center; margin: 20px 0;">' +
                '<button id="wpkj-alipay-check-status-btn" class="button" style="background: #1677FF; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-size: 14px;">' +
                '<?php echo esc_js(__('Check Payment Status', 'wpkj-fluentcart-alipay-payment')); ?>' +
                '</button>' +
                '<div id="wpkj-alipay-check-message" style="margin-top: 10px; font-size: 14px;"></div>' +
                '</div>';
            
            // Insert button after order info
            $('.fluent_cart_order_confirmation, .alipay-body, .order-info').first().after(buttonHtml);

            // Button click handler
            $('#wpkj-alipay-check-status-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $msg = $('#wpkj-alipay-check-message');
                
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Checking...', 'wpkj-fluentcart-alipay-payment')); ?>');
                $msg.html('');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'wpkj_alipay_manual_check_order_status',
                        order_uuid: '<?php echo esc_js($orderHash); ?>',
                        nonce: '<?php echo wp_create_nonce('wpkj_alipay_manual_check'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.data) {
                            var color = response.data.status === 'paid' ? '#52c41a' : 
                                       response.data.status === 'failed' ? '#f5222d' : '#faad14';
                            
                            $msg.html('<span style="color: ' + color + ';">' + response.data.message + '</span>');
                            
                            if (response.data.should_refresh) {
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            }
                        } else {
                            $msg.html('<span style="color: #f5222d;">' + (response.data ? response.data.message : '<?php echo esc_js(__('Check failed', 'wpkj-fluentcart-alipay-payment')); ?>') + '</span>');
                        }
                        
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Check Payment Status', 'wpkj-fluentcart-alipay-payment')); ?>');
                    },
                    error: function() {
                        $msg.html('<span style="color: #f5222d;"><?php echo esc_js(__('Network error', 'wpkj-fluentcart-alipay-payment')); ?></span>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Check Payment Status', 'wpkj-fluentcart-alipay-payment')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
