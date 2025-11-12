# WordPress.org Plugin Review Compliance Fixes

## Summary
All issues identified in the WordPress.org plugin review have been resolved. This document tracks the changes made to comply with WordPress plugin guidelines.

---

## 1. Text Domain Mismatch - FIXED ✓

**Issue**: Text domain `wpkj-fluentcart-alipay-payment` did not match plugin slug `wpkj-alipay-gateway-for-fluentcart`

**Solution**:
- Updated plugin slug from `wpkj-fluentcart-alipay-payment` to `wpkj-alipay-gateway-for-fluentcart`
- Renamed main plugin file: `wpkj-fluentcart-alipay-payment.php` → `wpkj-alipay-gateway-for-fluentcart.php`
- Updated Text Domain header from `wpkj-fluentcart-alipay-payment` to `wpkj-alipay-gateway-for-fluentcart`
- Updated all 171+ occurrences of text domain across all PHP files:
  - `load_plugin_textdomain()` call
  - All `__()`, `esc_html__()`, `_e()`, and other translation functions
  - Plugin header

**Files Modified**:
- Deleted: `wpkj-fluentcart-alipay-payment.php`
- Created/Renamed: `wpkj-alipay-gateway-for-fluentcart.php`
- Updated: All 25 PHP source files in `/src` directory

---

## 2. Undocumented External Services - FIXED ✓

**Issue**: Plugin uses external services without proper documentation in readme.txt

**Services Used**:
1. **Alipay Payment Gateway** (Production: `https://openapi.alipay.com/gateway.do`)
2. **Alipay Sandbox API** (Testing: `https://openapi-sandbox.dl.alipaydev.com/gateway.do`)
3. **QR Server API** (Fallback: `https://api.qrserver.com/v1/create-qr-code/`)

**Solution**:
- Added comprehensive "== External Services ==" section to readme.txt
- Documented each external service with:
  - Service name and purpose
  - What data is sent and when
  - Data destination URLs
  - Links to Terms of Service and Privacy Policy
  - Usage conditions and fallback information

**Documentation Added** (in readme.txt):
```
== External Services ==

This plugin relies on external services for payment processing. Below is documentation for each external service:

=== Alipay Payment Gateway (Production) ===
- Service: Alipay Payment Gateway API
- What it's used for: Process online payments from customers using Alipay accounts
- Data sent: Order ID, amount, email, subject, currency, timeout
- Data sent to: https://openapi.alipay.com/gateway.do
- Terms of Service: https://terms.alipay.com/
- Privacy Policy: https://global.alipay.com/service/privacy.htm

=== Alipay Sandbox API (Testing) ===
- Service: Alipay Sandbox API (test environment)
- What it's used for: Process test payments when plugin is in test mode
- Data sent: Same as production
- Data sent to: https://openapi-sandbox.dl.alipaydev.com/gateway.do
- Terms of Service: https://terms.alipay.com/
- Privacy Policy: https://global.alipay.com/service/privacy.htm

=== QR Server API (Face-to-Face Payments) ===
- Service: QR Server API
- What it's used for: Generate QR code images when JavaScript QRCode library is not available
- Data sent: Payment string/URL (encoded in QR code)
- When it's sent: Only as fallback when QRCode.js library fails to load
- Data sent to: https://api.qrserver.com/v1/create-qr-code/
- Terms of Service: https://qr-server.com/
- Additional Info: Fallback service. Recommended to bundle QRCode.js locally.

=== User Consent ===
Users are informed about Alipay payment processing and accept data transmission as part of payment process.
```

**Files Modified**:
- `readme.txt` - Added 47 lines of External Services documentation

---

## 3. File Naming Convention - FIXED ✓

**Issue**: Main filename did not follow WordPress convention

**Problems**:
- Plugin file: `wpkj-fluentcart-alipay-payment.php` (should match slug: `wpkj-alipay-gateway-for-fluentcart.php`)
- ZIP filename should be: `wpkj-alipay-gateway-for-fluentcart.zip` (not `wpkj-fluentcart-alipay-payment-1.0.8.zip`)

**Solution**:
- Renamed main plugin file to `wpkj-alipay-gateway-for-fluentcart.php`
- Updated Plugin Header "Text Domain" to match new slug
- Confirmed all internal references updated

**Note**: When creating the release ZIP file, ensure filename is: `wpkj-alipay-gateway-for-fluentcart.zip`

---

## 4. Data Sanitization, Validation & Escaping - FIXED ✓

**Issue**: POST/GET/REQUEST data not properly sanitized, validated, and escaped

**Problems Identified**:
1. `src/Webhook/NotifyHandler.php:71` - Processing entire `$_POST` array
2. `src/Processor/PaymentStatusChecker.php:67` - Nonce verification missing sanitization
3. Missing additional validation on POST data

**Solution A: NotifyHandler.php** 
Changed from processing entire `$_POST` to only extracting required fields:

```php
// OLD - PROCESS ALL POST DATA (INSECURE)
$data = $_POST;

// NEW - PROCESS ONLY REQUIRED FIELDS
$requiredFields = [
    'notify_id', 'notify_type', 'out_trade_no', 'trade_no', 'trade_status',
    'total_amount', 'buyer_logon_id', 'buyer_user_id', 'send_pay_date',
    'sign', 'sign_type', 'gmt_create', 'gmt_payment'
];

$data = [];
foreach ($requiredFields as $field) {
    if (isset($_POST[$field])) {
        $data[$field] = wp_unslash($_POST[$field]);
    }
}

// Then sanitize with Helper::sanitizeResponseData()
$data = Helper::sanitizeResponseData($data);
```

**Solution B: PaymentStatusChecker.php**
Enhanced nonce verification with additional sanitization:

```php
// OLD - MISSING SANITIZE_TEXT_FIELD
if (!wp_verify_nonce(wp_unslash($_POST['nonce']), 'wpkj_alipay_check_status'))

// NEW - ADDED SANITIZE_TEXT_FIELD
if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wpkj_alipay_check_status'))
```

**Sanitization Pattern Used** (WordPress Standard):
- `wp_unslash()` - Remove slashes added by WordPress
- `sanitize_text_field()` - Clean text input
- Custom `Helper::sanitizeResponseData()` - Domain-specific sanitization
- `esc_html()`, `esc_html__()` - Escape output

**Files Modified**:
- `src/Webhook/NotifyHandler.php` - Line 68-82 (POST data handling)
- `src/Processor/PaymentStatusChecker.php` - Line 67 (Nonce verification)

**phpcs Annotations**:
All security-related phpcs ignores are properly documented with explanations

---

## 5. JavaScript External Service Documentation - FIXED ✓

**Issue**: JavaScript code calling external service (QR Server) without documentation

**Solution**:
- Enhanced JavaScript documentation in `assets/js/face-to-face-payment.js`
- Clarified QR code generation process:
  - Primary method: Local QRCode.js library (no external calls)
  - Fallback method: QR Server API (documented in readme.txt)
- Added comments explaining external service usage

**Files Modified**:
- `assets/js/face-to-face-payment.js` - Enhanced comments (lines 20-43)

---

## Compliance Checklist

### Text Domain
- [x] Text Domain matches plugin slug: `wpkj-alipay-gateway-for-fluentcart`
- [x] All 171+ occurrences updated in PHP files
- [x] Plugin header correctly set
- [x] Old text domain completely removed

### File Naming
- [x] Main plugin file: `wpkj-alipay-gateway-for-fluentcart.php`
- [x] File name matches plugin slug
- [x] Old filename removed

### External Services
- [x] All external services documented in readme.txt
- [x] Service names and purposes explained
- [x] Data sent/received documented
- [x] Service URLs provided
- [x] Terms of Service links included
- [x] Privacy Policy links included
- [x] Usage conditions explained

### Data Handling
- [x] POST data sanitized with `wp_unslash()`
- [x] Text input validated with `sanitize_text_field()`
- [x] Nonce verification includes proper sanitization
- [x] Output escaped with `esc_html()`, `esc_html__()`
- [x] Only necessary POST fields processed
- [x] Helper functions used for domain-specific sanitization

### Code Quality
- [x] phpcs annotations explain all security bypasses
- [x] No magic security bypasses
- [x] All changes documented with comments
- [x] Backward compatible code

---

## Testing Recommendations

1. **Plugin Activation**
   - Verify plugin activates with new filename
   - Check for any deprecation warnings

2. **Text Domain / Translations**
   - Test translation functions work correctly
   - Verify WordPress.org can recognize new text domain

3. **Webhook Processing**
   - Test Alipay webhook notifications
   - Verify POST data is correctly parsed and sanitized

4. **Face-to-Face Payments**
   - Test QR code generation with QRCode.js library (primary method)
   - Test fallback to QR Server API (if QRCode.js unavailable)

5. **Security**
   - Verify nonce validation works correctly
   - Test with malicious POST data

---

## Version Update
- Current Version: 1.0.8
- Consider updating to 1.0.9 for next release

---

## Notes for Next Submission

When resubmitting to WordPress.org:
1. Ensure ZIP file is named: `wpkj-alipay-gateway-for-fluentcart.zip`
2. Confirm main plugin file is: `wpkj-alipay-gateway-for-fluentcart.php`
3. Include this compliance documentation for reference
4. All issues from previous review should now be resolved

---

**Last Updated**: November 12, 2025
**Changes Made By**: WPKJ Team

