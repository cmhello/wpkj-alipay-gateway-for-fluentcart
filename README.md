# WPKJ FluentCart Alipay Payment

A professional Alipay payment gateway plugin for FluentCart with automatic client detection and multi-platform support.

## Features

- **Multi-Platform Support**: Automatically detects and uses the appropriate payment interface
  - PC Web Payment (Desktop browsers)
  - Mobile WAP Payment (Mobile browsers)
  - Alipay App Payment (Alipay client)
- **Auto-Refund**: Automatic refund processing when orders are cancelled
- **Secure**: RSA2 signature encryption
- **Webhook Support**: Real-time payment notifications
- **Refund Support**: Process refunds from admin panel (manual & automatic)
- **Test Mode**: Sandbox environment for testing
- **Multi-Currency**: Support for 14+ currencies
- **i18n Ready**: Fully translatable

## Requirements

- FluentCart 1.2.0+
- WordPress 6.0+
- PHP 8.2+
- SSL Certificate (HTTPS) for production

## Installation

1. Upload plugin to `/wp-content/plugins/wpkj-fluentcart-alipay-payment/`
2. Activate through WordPress admin
3. Navigate to FluentCart > Settings > Payments
4. Configure Alipay credentials
5. Enable the gateway

## Configuration

### Obtaining Alipay Credentials

1. Register at [Alipay Open Platform](https://open.alipay.com/)
2. Create application
3. Generate RSA2 key pair
4. Upload public key to Alipay
5. Copy App ID and Alipay's public key

### Plugin Settings

1. **App ID**: Your Alipay application ID (16 digits)
2. **Private Key**: Your RSA private key
3. **Alipay Public Key**: Alipay's RSA public key
4. **Notify URL**: Configure this in your Alipay app settings

## Supported Currencies

CNY, USD, EUR, GBP, HKD, JPY, KRW, SGD, AUD, CAD, CHF, NZD, THB, MYR

## Auto-Refund Feature

The plugin supports automatic refunds when orders are cancelled:

1. **Enable**: Go to FluentCart > Settings > Payment Methods > Alipay
2. **Check**: "Enable automatic refund when order is cancelled"
3. **Save**: Click Save Settings

When enabled:
- System automatically refunds cancelled orders
- Refund processed through Alipay API
- Order activity logs updated
- Email notifications sent (if configured)

**Documentation**: See `AUTO_REFUND_GUIDE.md` for detailed information
**Test Page**: Access `test-auto-refund.php` to verify installation

## Development

### Directory Structure

```
wpkj-fluentcart-alipay-payment/
├── src/
│   ├── Gateway/          # Gateway implementation
│   ├── API/              # Alipay API communication
│   ├── Processor/        # Payment processing
│   ├── Webhook/          # Webhook handlers
│   ├── Utils/            # Utility classes
│   └── Detector/         # Client detection
├── assets/
│   ├── js/              # JavaScript files
│   └── css/             # Stylesheets
└── languages/           # Translation files
```

### Hooks & Filters

**Actions:**
- `wpkj_fc_alipay_before_payment` - Before payment processing
- `wpkj_fc_alipay_after_payment` - After payment success
- `wpkj_fc_alipay_payment_failed` - On payment failure

**Filters:**
- `wpkj_fc_alipay_payment_data` - Modify payment data
- `wpkj_fc_alipay_notify_url` - Custom notify URL
- `wpkj_fc_alipay_return_url` - Custom return URL

## Support

For issues and feature requests, please visit:
[https://wpkj.com/support](https://wpkj.com/support)

## License

GPL v2 or later

## Credits

Developed by WPKJ Team
