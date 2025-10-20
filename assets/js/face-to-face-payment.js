/**
 * Face-to-Face Payment Page Script
 * Handles QR code display and payment status polling
 */

(function($) {
    'use strict';

    // Ensure wpkj_alipay_f2f_data is available
    if (typeof wpkj_alipay_f2f_data === 'undefined') {
        console.error('Alipay F2F payment data not loaded');
        return;
    }

    $(document).ready(function() {
        initQRCode();
        startPaymentStatusPolling();
    });

    /**
     * Initialize QR code display
     */
    function initQRCode() {
        var qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' + 
            encodeURIComponent(wpkj_alipay_f2f_data.qr_code);
        
        $('#alipay-qrcode').html(
            '<img src="' + qrCodeUrl + '" alt="Alipay QR Code" style="width: 280px; height: 280px;">'
        );
    }

    /**
     * Start polling for payment status
     */
    function startPaymentStatusPolling() {
        var pollingInterval = 3000; // 3 seconds
        var maxAttempts = 200; // 10 minutes
        var currentAttempt = 0;
        var isRedirecting = false;

        /**
         * Check payment status via AJAX
         */
        function checkPaymentStatus() {
            if (isRedirecting) {
                return;
            }

            currentAttempt++;

            if (currentAttempt > maxAttempts) {
                clearInterval(pollingTimer);
                showTimeout();
                return;
            }

            $.ajax({
                url: wpkj_alipay_f2f_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpkj_alipay_check_payment_status',
                    transaction_uuid: wpkj_alipay_f2f_data.transaction_uuid,
                    nonce: wpkj_alipay_f2f_data.nonce
                },
                success: function(response) {
                    handleStatusResponse(response);
                },
                error: function(xhr, status, error) {
                    console.error('Payment status check failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    // Continue polling on error (network issues, etc.)
                }
            });
        }

        /**
         * Handle AJAX response
         */
        function handleStatusResponse(response) {
            if (isRedirecting) {
                return;
            }

            if (response.success && response.data) {
                var status = response.data.status;

                if (status === 'paid' || status === 'success') {
                    handlePaymentSuccess(response.data);
                } else if (status === 'failed' || status === 'cancelled') {
                    handlePaymentFailure(response.data);
                }
                // status === 'waiting' continues polling
            }
        }

        /**
         * Handle successful payment
         */
        function handlePaymentSuccess(data) {
            isRedirecting = true;
            clearInterval(pollingTimer);
            
            $('#payment-status').html(
                '<div class="status-text" style="color: #52c41a;">✓ ' + 
                wpkj_alipay_f2f_data.i18n.payment_success + 
                '</div>'
            );
            
            console.log('Payment successful, redirecting to:', data.redirect_url);
            
            setTimeout(function() {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else {
                    // Fallback: reload page which will trigger auto-redirect
                    window.location.reload();
                }
            }, 1000);
        }

        /**
         * Handle failed payment
         */
        function handlePaymentFailure(data) {
            clearInterval(pollingTimer);
            
            $('#payment-status').html(
                '<div class="status-text" style="color: #ff4d4f;">✗ ' + 
                (data.message || wpkj_alipay_f2f_data.i18n.payment_failed) + 
                '</div>'
            );
        }

        /**
         * Show timeout message
         */
        function showTimeout() {
            $('#payment-status').html(
                '<div class="status-text" style="color: #faad14;">⚠ ' + 
                wpkj_alipay_f2f_data.i18n.payment_timeout + 
                '</div>'
            );
        }

        // Start polling
        var pollingTimer = setInterval(checkPaymentStatus, pollingInterval);
        
        // Check immediately on load
        checkPaymentStatus();
    }

})(jQuery);
