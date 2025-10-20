<?php

namespace WPKJFluentCart\Alipay\Processor;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Face-to-Face Payment Page Handler
 * 
 * Intercepts custom checkout page requests and renders QR code payment interface
 */
class FaceToFacePageHandler
{
    /**
     * Register hooks
     * 
     * @return void
     */
    public function register()
    {
        add_action('template_redirect', [$this, 'handleFaceToFacePayment'], 1);
    }

    /**
     * Handle face-to-face payment page display
     * 
     * @return void
     */
    public function handleFaceToFacePayment()
    {
        // Debug: Log all query parameters
        if (isset($_GET['fluent-cart'])) {
            Logger::info('FaceToFace Handler Check', [
                'fluent_cart' => $_GET['fluent-cart'],
                'order_hash' => $_GET['order_hash'] ?? 'NOT_SET',
                'qr_code_set' => isset($_GET['qr_code']) ? 'YES' : 'NO',
                'trx_uuid' => $_GET['trx_uuid'] ?? 'NOT_SET'
            ]);
        }

        // Check for our custom F2F payment route
        if (!isset($_GET['fluent-cart']) || $_GET['fluent-cart'] !== 'alipay_f2f_payment') {
            return;
        }

        $orderHash = sanitize_text_field($_GET['order_hash'] ?? '');
        $qrCodeEncoded = sanitize_text_field($_GET['qr_code'] ?? '');
        $trxUuid = sanitize_text_field($_GET['trx_uuid'] ?? '');

        Logger::info('FaceToFace Handler Started', [
            'has_order_hash' => !empty($orderHash),
            'has_qr_code' => !empty($qrCodeEncoded),
            'has_trx_uuid' => !empty($trxUuid)
        ]);

        if (empty($orderHash) || empty($qrCodeEncoded) || empty($trxUuid)) {
            Logger::error('FaceToFace Handler Missing Parameters', [
                'order_hash' => empty($orderHash) ? 'EMPTY' : 'OK',
                'qr_code' => empty($qrCodeEncoded) ? 'EMPTY' : 'OK',
                'trx_uuid' => empty($trxUuid) ? 'EMPTY' : 'OK'
            ]);
            return;
        }

        $order = Order::query()->where('uuid', $orderHash)->first();
        if (!$order) {
            Logger::error('FaceToFace Handler Order Not Found', [
                'order_hash' => $orderHash
            ]);
            return;
        }

        $transaction = OrderTransaction::query()->where('uuid', $trxUuid)->first();
        if (!$transaction) {
            Logger::error('FaceToFace Handler Transaction Not Found', [
                'trx_uuid' => $trxUuid
            ]);
            return;
        }

        $qrCode = base64_decode($qrCodeEncoded);
        if (empty($qrCode)) {
            Logger::error('FaceToFace Handler QR Code Decode Failed', [
                'encoded_length' => strlen($qrCodeEncoded)
            ]);
            return;
        }

        Logger::info('Face-to-Face Payment Page Loaded', [
            'order_id' => $order->id,
            'transaction_uuid' => $trxUuid,
            'transaction_status' => $transaction->status
        ]);

        // If payment already completed, redirect immediately
        if ($transaction->status === \FluentCart\App\Helpers\Status::TRANSACTION_SUCCEEDED) {
            Logger::info('Payment Already Completed, Redirecting', [
                'transaction_uuid' => $trxUuid,
                'order_id' => $order->id
            ]);
            
            $storeSettings = new \FluentCart\Api\StoreSettings();
            $receiptPage = $storeSettings->getReceiptPage();
            
            if (empty($receiptPage)) {
                $receiptPage = home_url('/?fluent-cart=receipt');
            }
            
            // FluentCart receipt page expects 'order_hash' parameter
            $receiptUrl = add_query_arg([
                'order_hash' => $order->uuid
            ], $receiptPage);
            
            wp_redirect($receiptUrl);
            exit;
        }

        $this->renderPaymentPage($order, $transaction, $qrCode);
        exit;
    }

    /**
     * Render face-to-face payment page
     * 
     * @param Order $order Order instance
     * @param OrderTransaction $transaction Transaction instance
     * @param string $qrCode QR code string
     * @return void
     */
    private function renderPaymentPage($order, $transaction, $qrCode)
    {
        $siteName = get_bloginfo('name');
        $logoUrl = get_site_icon_url();
        
        // Enqueue jQuery
        wp_enqueue_script('jquery');
        
        // Enqueue CSS with file modification time as version
        $cssFile = WPKJ_FC_ALIPAY_PATH . 'assets/css/face-to-face-payment.css';
        $cssVersion = file_exists($cssFile) ? filemtime($cssFile) : WPKJ_FC_ALIPAY_VERSION;
        wp_enqueue_style(
            'wpkj-fc-alipay-f2f-styles',
            WPKJ_FC_ALIPAY_URL . 'assets/css/face-to-face-payment.css',
            [],
            $cssVersion
        );
        
        // Enqueue JavaScript with file modification time as version
        $jsFile = WPKJ_FC_ALIPAY_PATH . 'assets/js/face-to-face-payment.js';
        $jsVersion = file_exists($jsFile) ? filemtime($jsFile) : WPKJ_FC_ALIPAY_VERSION;
        wp_enqueue_script(
            'wpkj-fc-alipay-f2f-script',
            WPKJ_FC_ALIPAY_URL . 'assets/js/face-to-face-payment.js',
            ['jquery'],
            $jsVersion,
            true
        );

        // Prepare i18n strings
        $i18n = [
            'scan_title' => __('Scan QR Code to Pay with Alipay', 'wpkj-fluentcart-alipay-payment'),
            'waiting_payment' => __('Waiting for payment...', 'wpkj-fluentcart-alipay-payment'),
            'payment_success' => __('Payment successful! Redirecting...', 'wpkj-fluentcart-alipay-payment'),
            'payment_failed' => __('Payment failed', 'wpkj-fluentcart-alipay-payment'),
            'payment_timeout' => __('Payment timeout, please refresh and try again', 'wpkj-fluentcart-alipay-payment'),
            'order_info' => __('Order Information', 'wpkj-fluentcart-alipay-payment'),
            'order_number' => __('Order Number', 'wpkj-fluentcart-alipay-payment'),
            'amount' => __('Amount', 'wpkj-fluentcart-alipay-payment'),
            'scan_instruction' => __('Please open Alipay app and scan the QR code to complete payment', 'wpkj-fluentcart-alipay-payment'),
        ];
        
        // Localize script data
        wp_localize_script(
            'wpkj-fc-alipay-f2f-script',
            'wpkj_alipay_f2f_data',
            [
                'transaction_uuid' => $transaction->uuid,
                'order_uuid' => $order->uuid,
                'qr_code' => $qrCode,
                'ajax_url' => admin_url('admin-ajax.php'),
                'i18n' => $i18n
            ]
        );

        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($i18n['scan_title']); ?> - <?php echo esc_html($siteName); ?></title>
    <?php wp_head(); ?>
</head>
<body>
    <div class="f2f-container">
        <div class="f2f-header">
            <?php if ($logoUrl): ?>
                <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php echo esc_attr($siteName); ?>" style="height: 40px; margin-bottom: 15px;">
            <?php endif; ?>
            <h1><?php echo esc_html($i18n['scan_title']); ?></h1>
            <p><?php echo esc_html($i18n['scan_instruction']); ?></p>
        </div>

        <div class="f2f-body">
            <div class="order-info">
                <h3><?php echo esc_html($i18n['order_info']); ?></h3>
                <div class="order-info-row">
                    <span class="order-info-label"><?php echo esc_html($i18n['order_number']); ?>:</span>
                    <span class="order-info-value"><?php echo esc_html(!empty($order->invoice_no) ? $order->invoice_no : '#' . $order->id); ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><?php echo esc_html($i18n['amount']); ?>:</span>
                    <span class="amount-value"><?php echo esc_html($transaction->currency . ' ' . number_format($transaction->total / 100, 2)); ?></span>
                </div>
            </div>

            <div class="qr-container">
                <div id="alipay-qrcode"></div>
            </div>

            <div class="status-container" id="payment-status">
                <div class="status-text">
                    <span class="loading-spinner"></span>
                    <span id="status-message"><?php echo esc_html($i18n['waiting_payment']); ?></span>
                </div>
            </div>

            <div class="instruction">
                <?php echo esc_html__('The page will automatically refresh after payment is completed', 'wpkj-fluentcart-alipay-payment'); ?>
            </div>
        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
        <?php
    }
}
