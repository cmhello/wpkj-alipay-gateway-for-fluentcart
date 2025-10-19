/**
 * Alipay Face-to-Face Payment Handler
 * 
 * Handles QR code display and payment status polling for face-to-face payments
 */
(function($) {
    'use strict';

    const AlipayFaceToFace = {
        /**
         * Polling interval in milliseconds
         */
        pollingInterval: 3000,

        /**
         * Maximum polling attempts
         */
        maxAttempts: 200,

        /**
         * Current attempt count
         */
        currentAttempt: 0,

        /**
         * Polling timer
         */
        pollingTimer: null,

        /**
         * Initialize face-to-face payment
         * 
         * @param {Object} paymentData Payment data from server
         */
        init: function(paymentData) {
            this.orderUuid = paymentData.order.uuid;
            this.transactionUuid = paymentData.transaction.uuid;
            this.qrCode = paymentData.qr_code;

            this.renderQRCode();
            this.startPolling();
        },

        /**
         * Render QR code on payment page
         */
        renderQRCode: function() {
            const container = $('#alipay-qrcode-container');
            
            if (container.length === 0) {
                this.createQRCodeContainer();
            }

            // Use QRCode.js library if available, otherwise use simple image
            if (typeof QRCode !== 'undefined') {
                new QRCode(document.getElementById('alipay-qrcode'), {
                    text: this.qrCode,
                    width: 280,
                    height: 280,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            } else {
                // Fallback: generate QR code using Google Charts API
                const qrCodeImg = $('<img>')
                    .attr('src', 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' + encodeURIComponent(this.qrCode))
                    .attr('alt', 'Alipay QR Code')
                    .css({
                        'width': '280px',
                        'height': '280px',
                        'display': 'block',
                        'margin': '0 auto'
                    });
                
                $('#alipay-qrcode').html(qrCodeImg);
            }
        },

        /**
         * Create QR code container if not exists
         */
        createQRCodeContainer: function() {
            const i18n = window.wpkj_alipay_f2f_i18n || {};
            
            const container = $('<div>')
                .attr('id', 'alipay-qrcode-container')
                .css({
                    'text-align': 'center',
                    'padding': '30px',
                    'background': '#fff',
                    'border-radius': '8px',
                    'box-shadow': '0 2px 8px rgba(0,0,0,0.1)'
                });

            const title = $('<h3>')
                .text(i18n.scan_title || 'Scan QR Code to Pay with Alipay')
                .css({
                    'margin-bottom': '20px',
                    'color': '#1678FF',
                    'font-size': '20px'
                });

            const qrcodeDiv = $('<div>')
                .attr('id', 'alipay-qrcode')
                .css({
                    'margin': '20px auto',
                    'padding': '10px',
                    'background': '#fff',
                    'display': 'inline-block'
                });

            const statusDiv = $('<div>')
                .attr('id', 'alipay-payment-status')
                .css({
                    'margin-top': '20px',
                    'color': '#666',
                    'font-size': '14px'
                })
                .html('<span class="loading-spinner"></span> ' + (i18n.waiting_payment || 'Waiting for payment...'));

            container.append(title, qrcodeDiv, statusDiv);
            
            const targetContainer = $('.fct_payment_modal_body, .fct-payment-content, body').first();
            targetContainer.prepend(container);
        },

        /**
         * Start polling payment status
         */
        startPolling: function() {
            this.currentAttempt = 0;
            this.pollingTimer = setInterval(() => {
                this.checkPaymentStatus();
            }, this.pollingInterval);
        },

        /**
         * Stop polling
         */
        stopPolling: function() {
            if (this.pollingTimer) {
                clearInterval(this.pollingTimer);
                this.pollingTimer = null;
            }
        },

        /**
         * Check payment status via AJAX
         */
        checkPaymentStatus: function() {
            this.currentAttempt++;

            if (this.currentAttempt > this.maxAttempts) {
                this.stopPolling();
                this.showTimeout();
                return;
            }

            $.ajax({
                url: window.fluent_cart_vars.ajaxurl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'wpkj_alipay_check_payment_status',
                    transaction_uuid: this.transactionUuid,
                    nonce: window.wpkj_alipay_nonce || ''
                },
                success: (response) => {
                    if (response.success && response.data) {
                        const status = response.data.status;

                        if (status === 'paid' || status === 'success') {
                            this.stopPolling();
                            this.showSuccess(response.data);
                        } else if (status === 'failed' || status === 'cancelled') {
                            this.stopPolling();
                            const i18n = window.wpkj_alipay_f2f_i18n || {};
                            this.showError(response.data.message || i18n.payment_failed || 'Payment failed');
                        }
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Payment status check failed:', error);
                }
            });
        },

        /**
         * Show payment success
         * 
         * @param {Object} data Payment data
         */
        showSuccess: function(data) {
            const i18n = window.wpkj_alipay_f2f_i18n || {};
            $('#alipay-payment-status')
                .html('<span style="color: #52c41a;">✓</span> ' + (i18n.payment_success || 'Payment successful! Redirecting...'))
                .css('color', '#52c41a');

            setTimeout(() => {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else {
                    window.location.reload();
                }
            }, 1500);
        },

        /**
         * Show payment error
         * 
         * @param {String} message Error message
         */
        showError: function(message) {
            $('#alipay-payment-status')
                .html('<span style="color: #ff4d4f;">✗</span> ' + message)
                .css('color', '#ff4d4f');
        },

        /**
         * Show timeout message
         */
        showTimeout: function() {
            const i18n = window.wpkj_alipay_f2f_i18n || {};
            $('#alipay-payment-status')
                .html('<span style="color: #faad14;">⚠</span> ' + (i18n.payment_timeout || 'Payment timeout, please refresh and try again'))
                .css('color', '#faad14');
        }
    };

    // Listen for face-to-face payment response
    $(document).on('fluent_cart_payment_response', function(event, response) {
        if (response.status === 'success' && 
            response.nextAction === 'qrcode' && 
            response.data && 
            response.data.qr_code) {
            
            AlipayFaceToFace.init(response.data);
        }
    });

})(jQuery);
