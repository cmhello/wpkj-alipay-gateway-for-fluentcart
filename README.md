# WPKJ Payment Gateway for FluentCart with Alipay

A professional, feature-rich Alipay payment gateway for FluentCart with intelligent client detection, full subscription support, and enterprise-grade security.

## ✨ Features

### 🎯 Multi-Platform Payment Support
Automatically detects user environment and selects the optimal payment interface:
- **PC Web Payment** - Desktop browsers with form-based checkout
- **Mobile WAP Payment** - Mobile browsers with optimized interface
- **Face-to-Face Payment** - QR code scanning for offline payments
- **Alipay App Payment** - Native app payment experience

### 🔄 Advanced Subscription Management
Comprehensive subscription payment support with dual-mode strategy:

#### Automatic Renewal (Recurring Agreement) ⭐ Recommended
- True auto-renewal through Alipay agreement protocol
- 95%+ renewal success rate
- Requires merchant recurring payment service activation
- Best for high-value subscriptions

#### Manual Renewal (Fallback Mode)
- Customer manually completes each renewal payment
- No special merchant requirements
- Automatic fallback if agreement fails
- Full FluentCart subscription feature integration

**Subscription Features:**
- ✅ Initial payments with setup fees
- ✅ Trial period support (0-365 days)
- ✅ Flexible billing intervals (daily/weekly/monthly/yearly)
- ✅ Limited and unlimited billing cycles
- ✅ Subscription cancellation sync with orders
- ✅ Automatic status synchronization
- ✅ Renewal order generation
- ✅ Intelligent degradation strategy

### 💰 Comprehensive Refund System
- **Automatic Refund**: Orders cancelled = instant refund
- **Manual Refund**: Process from FluentCart admin
- **Full & Partial**: Support both refund types
- **Activity Logging**: All refunds tracked in order logs
- **Configurable**: Enable/disable auto-refund per needs

### 🔒 Enterprise Security
- **RSA2 Encryption**: Industry-standard 2048-bit encryption
- **Signature Verification**: All requests validated
- **HTTPS Required**: SSL certificate for production
- **Webhook Validation**: Secure async notifications
- **Amount Verification**: Double-check payment amounts

### 🌐 Internationalization
- **14+ Currencies**: CNY, USD, EUR, GBP, HKD, JPY, KRW, SGD, AUD, CAD, CHF, NZD, THB, MYR
- **Full i18n**: Complete translation support
- **POT File**: Translation template included
- **Chinese & English**: Built-in translations

### 🛠️ Developer Features
- **Test Mode**: Sandbox environment for development
- **Detailed Logging**: Debug mode with comprehensive logs
- **Webhook Integration**: Real-time payment notifications
- **Clean Architecture**: PSR-4 autoloading, namespaced
- **Hooks & Filters**: Extensive customization points
- **Custom Payment API**: REST API for external system integration

## 📋 Requirements

- **WordPress**: 6.5 or higher
- **PHP**: 8.2 or higher
- **FluentCart**: 1.2.0 or higher
- **SSL Certificate**: HTTPS required for production
- **Alipay Account**: Merchant account on Alipay Open Platform

## 🚀 Installation

### Standard Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through WordPress admin panel
3. Navigate to **FluentCart → Settings → Payment Methods**
4. Configure Alipay credentials
5. Enable the payment gateway
6. Configure webhook URL in Alipay dashboard

### Via WP-CLI

```bash
wp plugin install wpkj-alipay-gateway-for-fluentcart --activate
```

## ⚙️ Configuration

### Step 1: Obtain Alipay Credentials

1. Register at [Alipay Open Platform](https://open.alipay.com/)
2. Create new application (Web & Mobile)
3. Generate RSA2 key pair:
   ```bash
   openssl genrsa -out private_key.pem 2048
   openssl rsa -in private_key.pem -pubout -out public_key.pem
   ```
4. Upload public key to Alipay dashboard
5. Copy Alipay's public key from dashboard
6. Note down your 16-digit App ID

### Step 2: Plugin Configuration

#### Live Environment
- **App ID**: Your 16-digit application ID
- **Application Private Key**: Your RSA private key (remove headers)
- **Alipay Public Key**: Alipay's RSA public key (remove headers)

#### Test Environment  
- **Test App ID**: Sandbox application ID
- **Test Private Key**: Sandbox RSA private key
- **Test Public Key**: Sandbox Alipay public key

#### Additional Settings
- **Notify URL**: Copy and configure in Alipay dashboard
- **Enable Auto-Refund**: Check to enable automatic refunds
- **Enable Face-to-Face**: Enable QR code payment for PC/Desktop

### Step 3: Subscription Settings (Optional)

For recurring payments with automatic renewal:

1. **Enable Recurring Payment**: Check the option
2. **Personal Product Code**: Enter code from Alipay (usually `GENERAL_WITHHOLDING_P`)
3. **Merchant Agreement**: Ensure recurring service is activated in Alipay dashboard

**Note**: Without recurring service, manual renewal mode activates automatically.

### Step 4: Order Cancellation Settings

- **Auto-cancel Subscription**: When checked, cancelling initial subscription order automatically cancels the subscription
- **Renewal Orders**: Cancelling renewal orders never affects subscription status

## 💼 Usage

### For Customers

#### Desktop Payment
1. Add products to cart
2. Proceed to checkout
3. Select "Alipay" as payment method
4. Choose payment type:
   - **Web Payment**: Redirects to Alipay checkout
   - **QR Code**: Scan with Alipay app (if enabled)
5. Complete payment
6. Automatic redirect to confirmation page

#### Mobile Payment
1. Browse on mobile device
2. Select Alipay at checkout
3. Redirects to Alipay mobile interface
4. Complete in Alipay app or mobile browser
5. Returns to site after payment

### For Store Owners

#### Processing Refunds

**Automatic** (Recommended):
1. Cancel order in FluentCart
2. Refund processes automatically
3. Check order activity log for confirmation

**Manual**:
1. Go to **FluentCart → Orders**
2. Open order details
3. Click **Refund** button
4. Enter amount and reason
5. Process refund

#### Managing Subscriptions

1. View all subscriptions in **FluentCart → Subscriptions**
2. Check renewal schedule and payment status
3. Manual actions:
   - Cancel subscription
   - Update billing schedule  
   - Process manual renewal
4. Monitor subscription logs for automatic renewals

## 🔧 Developer Guide

### Directory Structure

```
wpkj-alipay-gateway-for-fluentcart/
├── src/
│   ├── API/              # Alipay API communication
│   │   └── AlipayAPI.php
│   ├── Config/           # Configuration management
│   │   └── AlipayConfig.php
│   ├── Detector/         # Client type detection
│   │   └── ClientDetector.php
│   ├── Gateway/          # Payment gateway core
│   │   ├── AlipayGateway.php
│   │   └── AlipaySettingsBase.php
│   ├── Listeners/        # Event listeners
│   │   └── OrderCancelListener.php
│   ├── Processor/        # Payment processors
│   │   ├── FaceToFacePageHandler.php
│   │   ├── PaymentProcessor.php
│   │   ├── PaymentStatusChecker.php
│   │   └── RefundProcessor.php
│   ├── Services/         # Business logic services
│   │   ├── CustomPaymentService.php
│   │   ├── EncodingService.php
│   │   ├── Logger.php
│   │   └── OrderService.php
│   ├── Subscription/     # Subscription management
│   │   ├── AlipayRecurringAgreement.php
│   │   └── AlipaySubscriptionProcessor.php
│   ├── Utils/            # Utility functions
│   │   └── Helper.php
│   └── Webhook/          # Webhook handlers
│       ├── NotifyHandler.php
│       └── ReturnHandler.php
│   ├── API/              # REST API endpoints
│   │   └── CustomPaymentAPI.php
├── assets/
│   ├── css/             # Stylesheets
│   └── js/              # JavaScript files
├── languages/           # Translation files
│   ├── wpkj-alipay-gateway-for-fluentcart.pot
│   └── wpkj-alipay-gateway-for-fluentcart-zh_CN.po
└── docs/                # Additional documentation
```

### Available Hooks

#### Actions

```php
// Before payment processing
do_action('wpkj_fc_alipay/before_payment', $transaction, $order);

// After successful payment
do_action('wpkj_fc_alipay/payment_success', $transaction, $order);

// On payment failure
do_action('wpkj_fc_alipay/payment_failed', $transaction, $error);

// Before refund processing  
do_action('wpkj_fc_alipay/before_refund', $order, $amount);

// After successful refund
do_action('wpkj_fc_alipay/refund_success', $order, $refund_id);
```

#### Filters

```php
// Modify payment parameters before sending to Alipay
add_filter('wpkj_fc_alipay/payment_params', function($params, $order) {
    // Customize payment data
    return $params;
}, 10, 2);

// Customize notify URL
add_filter('wpkj_fc_alipay/notify_url', function($url) {
    return 'https://yourdomain.com/custom-notify';
});

// Customize return URL after payment
add_filter('wpkj_fc_alipay/return_url', function($url, $transaction) {
    return add_query_arg('custom', 'param', $url);
}, 10, 2);

// Modify subject line for payments
add_filter('wpkj_fc_alipay/payment_subject', function($subject, $order) {
    return 'Custom: ' . $subject;
}, 10, 2);
```

### Example: Custom Payment Flow

```php
// Modify payment data before processing
add_filter('wpkj_fc_alipay/payment_params', function($params, $order) {
    // Add custom metadata
    $params['passback_params'] = urlencode(json_encode([
        'custom_field' => 'value',
        'order_meta' => $order->id
    ]));
    
    return $params;
}, 10, 2);

// Log all successful payments
add_action('wpkj_fc_alipay/payment_success', function($transaction, $order) {
    error_log(sprintf(
        'Alipay payment success: Order #%d, Amount: %s',
        $order->id,
        $transaction->total
    ));
}, 10, 2);
```

## 🐛 Troubleshooting

### Common Issues

**Issue**: Payment fails with "illegal sign" error  
**Solution**: Check RSA key configuration, ensure no extra spaces or headers

**Issue**: Chinese characters showing as garbled text  
**Solution**: Verify charset is set to UTF-8, check EncodingService

**Issue**: Webhook not receiving notifications  
**Solution**: 
1. Verify notify URL in Alipay dashboard
2. Check firewall allows Alipay IPs
3. Enable debug logging to see webhook calls

**Issue**: Refund fails  
**Solution**: 
1. Check transaction exists in Alipay
2. Verify refund amount ≤ original amount
3. Ensure order is paid status

**Issue**: Subscription renewal not working  
**Solution**:
1. Verify recurring service is activated
2. Check agreement status in Alipay dashboard
3. Review subscription logs for errors
4. Confirm customer hasn't cancelled agreement

### Debug Mode

Enable debug logging:

1. Define in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

2. Check logs in `wp-content/debug.log`

3. Look for entries with `[WPKJ Alipay]` prefix

## 📚 Additional Resources

- [Alipay Open Platform Documentation](https://opendocs.alipay.com/)
- [RSA Key Generation Guide](https://opendocs.alipay.com/common/02kdnc)
- [Webhook Configuration](https://opendocs.alipay.com/open/270/105902)
- [Recurring Payment Service](https://opendocs.alipay.com/open/20190319114403226822)
- [FluentCart Documentation](https://fluentcart.com/docs/)

## 💬 Support

For support, bug reports, and feature requests:

- **Website**: [https://www.wpdaxue.com](https://www.wpdaxue.com)
- **Documentation**: [https://www.wpdaxue.com/wpkj-alipay-gateway-for-fluentcart.html](https://www.wpdaxue.com/wpkj-alipay-gateway-for-fluentcart.html)
- **Email**: support@wpdaxue.com

## 📄 License

This plugin is licensed under GPL v2 or later.

```
Copyright (C) 2025 WPDAXUE.COM

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## 👥 Credits

Developed and maintained by **WPDAXUE.COM**

- Lead Developer: WPDAXUE.COM
- Contributors: Community
- Based on: FluentCart Payment Gateway Framework
- Tested with: WordPress 6.8.3, FluentCart 1.2.0+

---

**Made with ❤️ by WPDAXUE.COM**
