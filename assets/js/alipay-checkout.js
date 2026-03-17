/**
 * Alipay Checkout Handler
 * Handles description display and submit button enabling for Alipay payment gateway
 */
window.addEventListener('fluent_cart_load_payments_alipay', function(event) {
    // Get submit button configuration
    const submitButton = window.fluentcart_checkout_vars?.submit_button;
    
    // Get payment container for this gateway
    const container = document.querySelector('.fluent-cart-checkout_embed_payment_container_alipay');
    
    // Get translations/description from localized data
    const alipayData = window.wpkj_fc_alipay_data || {};
    const description = alipayData.description || 'Pay securely with Alipay';
    
    // Display description in the container
    if (container) {
        container.innerHTML = `<p>${description}</p>`;
    }
    
    // Mark Alipay as ready
    window.is_alipay_ready = true;
    
    // Enable the checkout submit button
    if (event.detail && event.detail.paymentLoader) {
        event.detail.paymentLoader.enableCheckoutButton(submitButton?.text || 'Place Order');
    }
});
