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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment page route check, no sensitive action
        if (isset($_GET['fluent-cart'])) {
            Logger::info('FaceToFace Handler Check', [
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Debug logging only
                'fluent_cart' => sanitize_text_field(wp_unslash($_GET['fluent-cart'])),
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Debug logging only
                'order_hash' => isset($_GET['order_hash']) ? sanitize_text_field(wp_unslash($_GET['order_hash'])) : 'NOT_SET',
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Debug logging only
                'qr_code_set' => isset($_GET['qr_code']) ? 'YES' : 'NO',
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Debug logging only
                'trx_uuid' => isset($_GET['trx_uuid']) ? sanitize_text_field(wp_unslash($_GET['trx_uuid'])) : 'NOT_SET'
            ]);
        }

        // Check for our custom F2F payment route
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Route check only
        if (!isset($_GET['fluent-cart']) || wp_unslash($_GET['fluent-cart']) !== 'alipay_f2f_payment') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Payment page parameters
        $orderHash = isset($_GET['order_hash']) ? sanitize_text_field(wp_unslash($_GET['order_hash'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Payment page parameters
        $qrCodeEncoded = isset($_GET['qr_code']) ? sanitize_text_field(wp_unslash($_GET['qr_code'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Payment page parameters
        $trxUuid = isset($_GET['trx_uuid']) ? sanitize_text_field(wp_unslash($_GET['trx_uuid'])) : '';

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
            
            // Use Transaction's getReceiptPageUrl() method (FluentCart standard)
            // This uses StoreSettings and does NOT include download parameter
            $receiptUrl = $transaction->getReceiptPageUrl(true);
            
            // Add order hash for additional tracking
            $receiptUrl = add_query_arg([
                'order_hash' => $order->uuid
            ], $receiptUrl);
            
            wp_safe_redirect($receiptUrl);
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
        
        // Enqueue QRCode.js library for client-side QR code generation
        // This avoids URL encoding issues with Chinese characters
        // Note: The library file should be downloaded and placed in assets/js/vendor/
        $qrCodeFile = WPKJ_FC_ALIPAY_PATH . 'assets/js/vendor/qrcode.min.js';
        $qrCodeVersion = file_exists($qrCodeFile) ? filemtime($qrCodeFile) : WPKJ_FC_ALIPAY_VERSION;
        wp_enqueue_script(
            'qrcodejs',
            WPKJ_FC_ALIPAY_URL . 'assets/js/vendor/qrcode.min.js',
            [],
            $qrCodeVersion,
            true
        );
        
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
            'scan_title' => __('Scan QR Code to Pay with Alipay', 'wpkj-alipay-gateway-for-fluentcart'),
            'waiting_payment' => __('Waiting for payment...', 'wpkj-alipay-gateway-for-fluentcart'),
            'payment_success' => __('Payment successful! Redirecting...', 'wpkj-alipay-gateway-for-fluentcart'),
            'payment_failed' => __('Payment failed', 'wpkj-alipay-gateway-for-fluentcart'),
            'payment_timeout' => __('Payment timeout, please refresh and try again', 'wpkj-alipay-gateway-for-fluentcart'),
            'order_info' => __('Order Information', 'wpkj-alipay-gateway-for-fluentcart'),
            'order_number' => __('Order Number', 'wpkj-alipay-gateway-for-fluentcart'),
            'amount' => __('Amount', 'wpkj-alipay-gateway-for-fluentcart'),
            'scan_instruction' => __('Please open Alipay app and scan the QR code to complete payment', 'wpkj-alipay-gateway-for-fluentcart'),
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
                'nonce' => wp_create_nonce('wpkj_alipay_f2f_check_payment_status_nonce'),
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
                <?php echo esc_html__('The page will automatically refresh after payment is completed', 'wpkj-alipay-gateway-for-fluentcart'); ?>
            </div>

            <div class="security-footer">
                <svg class="security-shield" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="#1678FF">
                    <path d="M12 2L4 5v6.09c0 5.05 3.41 9.76 8 10.91 4.59-1.15 8-5.86 8-10.91V5l-8-3zm6 9.09c0 4-2.55 7.7-6 8.83-3.45-1.13-6-4.82-6-8.83V6.31l6-2.12 6 2.12v4.78z"/>
                    <path d="M9.5 11.5l1.41 1.41L15.5 8.34 14.09 6.93l-3.18 3.18-1.41-1.42z"/>
                </svg>
                <span class="security-text"><?php echo esc_html__('Secure payment service provided by Alipay', 'wpkj-alipay-gateway-for-fluentcart'); ?></span>
            </div>
        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
        <?php
    }
}
