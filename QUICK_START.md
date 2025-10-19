# Quick Start Guide - WPKJ FluentCart Alipay Payment

## Plugin Installation

The plugin has been successfully created and is ready for activation!

### Location
```
/www/wwwroot/waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/
```

### File Statistics
- **Total PHP Lines**: 1,990 lines
- **Total Files**: 20 files
- **Syntax Check**: ✅ All files passed (0 errors)

## Activation Steps

### 1. Activate the Plugin

Go to WordPress Admin:
```
Dashboard → Plugins → Installed Plugins
```

Find "WPKJ FluentCart Alipay Payment" and click "Activate"

### 2. Configure Settings

Navigate to:
```
FluentCart → Settings → Payments → Alipay
```

### 3. Enter Credentials

#### For Testing (Sandbox Mode)
1. Set Store Mode to "Test" (in FluentCart general settings)
2. Enter Test credentials:
   - **Test App ID**: Your Alipay sandbox App ID (16 digits)
   - **Test Private Key**: Your RSA private key (paste without header/footer)
   - **Test Alipay Public Key**: Alipay's RSA public key (paste without header/footer)

**Important**: When entering RSA keys, paste only the key content without the header (`-----BEGIN RSA PRIVATE KEY-----`) and footer (`-----END RSA PRIVATE KEY-----`). The plugin will automatically add these when needed.

#### For Production (Live Mode)
1. Set Store Mode to "Live" (in FluentCart general settings)
2. Enter Live credentials:
   - **Live App ID**: Your Alipay production App ID
   - **Live Private Key**: Your RSA private key (paste without header/footer)
   - **Live Alipay Public Key**: Alipay's RSA public key (paste without header/footer)

### 4. Configure Notify URL

Copy the Notify URL from plugin settings:
```
https://yoursite.com/?fct_payment_listener=1&method=alipay
```

Add this URL to your Alipay application settings:
1. Login to [Alipay Open Platform](https://open.alipay.com/)
2. Navigate to your application
3. Go to Development Settings → Interface Signing Method
4. Set Asynchronous Notification URL to the copied URL
5. Save settings

### 5. Enable Gateway

In FluentCart payment settings:
1. Toggle "Active" to ON for Alipay
2. Click "Save Settings"

## Testing Checklist

### Sandbox Testing
- [ ] Plugin activated successfully
- [ ] Sandbox credentials configured
- [ ] Gateway appears in checkout
- [ ] Can initiate payment
- [ ] Redirects to Alipay sandbox
- [ ] Complete test payment
- [ ] Verify webhook received
- [ ] Order status updated correctly
- [ ] Test refund functionality

### Device Testing
- [ ] Test on desktop browser (PC Web Payment)
- [ ] Test on mobile browser (WAP Payment)
- [ ] Test in Alipay app (App Payment)

### Currency Testing
- [ ] Test with CNY
- [ ] Test with USD (if supported)
- [ ] Verify unsupported currencies show error

## Obtaining Alipay Credentials

### Step 1: Register on Alipay Open Platform
Visit: https://open.alipay.com/

### Step 2: Create Application
1. Login to your account
2. Go to "Console" → "My Applications"
3. Click "Create Application"
4. Fill in application details
5. Submit for review

### Step 3: Generate RSA Key Pair

#### Using OpenSSL (Linux/Mac):
```bash
# Generate private key
openssl genrsa -out private_key.pem 2048

# Generate public key
openssl rsa -in private_key.pem -pubout -out public_key.pem

# View private key (copy this to plugin settings)
cat private_key.pem

# View public key (upload this to Alipay)
cat public_key.pem
```

#### Using Alipay's Tool:
Download from: https://opendocs.alipay.com/common/02khjo

### Step 4: Configure Application
1. Upload your public key to Alipay
2. Download Alipay's public key
3. Copy App ID (16 digits)
4. Configure Notify URL

### Step 5: Get Sandbox Credentials (for testing)
1. Go to "Sandbox Environment"
2. Copy Sandbox App ID
3. Upload your public key
4. Download Alipay sandbox public key

## Supported Payment Methods

The plugin automatically selects the appropriate payment method:

| Device | Payment Method | API Method |
|--------|---------------|------------|
| Desktop Browser | PC Web Payment | alipay.trade.page.pay |
| Mobile Browser | WAP Payment | alipay.trade.wap.pay |
| Alipay App | App Payment | alipay.trade.app.pay |

## Supported Currencies

- CNY (Chinese Yuan) - Primary
- USD (US Dollar)
- EUR (Euro)
- GBP (British Pound)
- HKD (Hong Kong Dollar)
- JPY (Japanese Yen)
- KRW (Korean Won)
- SGD (Singapore Dollar)
- AUD (Australian Dollar)
- CAD (Canadian Dollar)
- CHF (Swiss Franc)
- NZD (New Zealand Dollar)
- THB (Thai Baht)
- MYR (Malaysian Ringgit)

## Troubleshooting

### Plugin Not Appearing in Payments List
**Solution**: Make sure FluentCart 1.2.0+ is installed and activated

### "Invalid Credentials" Error
**Solution**: 
1. Verify App ID is 16 digits
2. Check private key format (should include header/footer)
3. Ensure using correct mode (test/live) credentials

### Payment Not Redirecting
**Solution**:
1. Check PHP error logs
2. Enable WordPress debug mode
3. Check FluentCart logs (Activity section)

### Webhook Not Working
**Solution**:
1. Verify Notify URL is publicly accessible
2. Check if signature verification is failing
3. Review Alipay notification logs
4. Temporarily disable signature verification (test only)

### Amount Mismatch Error
**Solution**:
1. Check currency conversion
2. Verify tax calculations
3. Review shipping cost calculations

## Debug Mode

To enable detailed logging:

1. Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

2. Check logs at:
```
/wp-content/debug.log
```

3. Review FluentCart activity logs in admin panel

## Support Resources

### Documentation
- FluentCart Docs: https://fluentcart.com/docs
- Alipay API Docs: https://opendocs.alipay.com/

### Support Channels
- Plugin Support: https://wpkj.com/support
- FluentCart Support: https://fluentcart.com/support

## Production Deployment Checklist

Before going live:

- [ ] Obtain production Alipay credentials
- [ ] Configure live settings in plugin
- [ ] Set FluentCart to Live mode
- [ ] Verify HTTPS is enabled
- [ ] Configure production Notify URL in Alipay
- [ ] Test with small transaction
- [ ] Monitor first few transactions
- [ ] Set up transaction monitoring
- [ ] Configure email notifications
- [ ] Document credentials securely
- [ ] Backup settings

## Next Steps

1. **Test Thoroughly**: Complete all testing checklist items
2. **Review Logs**: Check for any errors or warnings
3. **Monitor Transactions**: Watch first few payments closely
4. **Customer Support**: Prepare support documentation
5. **Performance**: Monitor page load times
6. **Security**: Regular security audits

## Version Information

- **Plugin Version**: 1.0.0
- **FluentCart Required**: 1.2.0+
- **WordPress Required**: 6.0+
- **PHP Required**: 8.2+
- **Release Date**: 2025-10-19

---

**Developed by WPKJ Team**
**Documentation Last Updated**: 2025-10-19
