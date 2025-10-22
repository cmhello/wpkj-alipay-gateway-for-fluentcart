=== WPKJ FluentCart Alipay Payment ===
Contributors: cmhello
Donate link: https://www.wpdaxue.com/wpkj-fluentcart-alipay-payment
Tags: fluentcart, alipay, payment gateway, china payment
Requires at least: 6.5
Tested up to: 6.8.3
Requires PHP: 8.2
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional Alipay payment gateway for FluentCart with multi-platform support, subscriptions, and automatic refunds.

== Description ==

WPKJ FluentCart Alipay Payment is a feature-rich, enterprise-grade payment gateway that seamlessly integrates Alipay with FluentCart. It intelligently detects user environments and automatically selects the optimal payment interface for the best user experience.

= Key Features =

* **Multi-Platform Payment Support**
  - PC Web Payment for desktop browsers
  - Mobile WAP Payment for mobile browsers
  - Face-to-Face Payment with QR codes
  - Alipay App Payment for native experience [Not yet completed]

* **Advanced Subscription Management**
  - Automatic Renewal via Alipay Recurring Agreement
  - Manual Renewal fallback mode
  - Trial period support (0-365 days)
  - Flexible billing intervals (daily, weekly, monthly, yearly)
  - Limited and unlimited billing cycles
  - Subscription cancellation sync with orders

* **Comprehensive Refund System**
  - Automatic refunds when orders are cancelled
  - Manual refund processing from admin
  - Full and partial refund support
  - Detailed activity logging

* **Enterprise Security**
  - RSA2 2048-bit encryption
  - Signature verification for all requests
  - HTTPS/SSL required for production
  - Webhook validation
  - Amount verification

* **International & Multi-Currency**
  - 14+ currencies supported
  - Full internationalization (i18n) ready
  - Translation template (POT) included
  - Built-in Chinese & English translations

= Supported Currencies =

CNY (Chinese Yuan), USD, EUR, GBP, HKD, JPY, KRW, SGD, AUD, CAD, CHF, NZD, THB, MYR

= Requirements =

* FluentCart 1.2.0 or higher
* WordPress 6.5 or higher
* PHP 8.2 or higher
* SSL Certificate (HTTPS) for production
* Alipay merchant account

= Developer Friendly =

* Clean PSR-4 autoloading architecture
* Comprehensive hooks and filters
* Debug logging support
* Sandbox/Test mode
* Extensive documentation

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Search for "WPKJ FluentCart Alipay Payment"
4. Click "Install Now" and then "Activate"
5. Go to FluentCart > Settings > Payment Methods
6. Configure your Alipay credentials

= Manual Installation =

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/wpkj-fluentcart-alipay-payment/`
3. Activate through the 'Plugins' menu in WordPress
4. Navigate to FluentCart > Settings > Payment Methods
5. Configure Alipay gateway settings
6. Enable the payment method

= Configuration Steps =

1. **Obtain Credentials from Alipay:**
   - Register at [Alipay Open Platform](https://open.alipay.com/)
   - Create a new application
   - Generate RSA2 key pair
   - Upload public key to Alipay
   - Get your 16-digit App ID
   - Copy Alipay's public key

2. **Configure Plugin Settings:**
   - Enter App ID, Private Key, and Alipay Public Key
   - Configure Notify URL in Alipay dashboard
   - Enable auto-refund (optional)
   - Enable Face-to-Face payment (optional)
   - For subscriptions: Enter Personal Product Code

3. **Test Your Setup:**
   - Use sandbox mode for testing
   - Make a test purchase
   - Verify webhook notifications
   - Test refund functionality

== Frequently Asked Questions ==

= Where do I get Alipay credentials? =

Register for a merchant account at [Alipay Open Platform](https://open.alipay.com/). After creating an application, you'll receive an App ID and can generate RSA key pairs for secure communication.

= Does this plugin support subscription payments? =

Yes! The plugin fully supports FluentCart subscriptions with two modes:
1. **Automatic Renewal** (Recommended) - Requires Alipay recurring payment service
2. **Manual Renewal** - Fallback mode, works for all merchants

= Can I test before going live? =

Absolutely! Use Alipay's sandbox environment for testing. Configure test credentials in the plugin settings and make test transactions before enabling live mode.

= What currencies are supported? =

The plugin supports 14+ currencies including CNY, USD, EUR, GBP, HKD, JPY, KRW, SGD, AUD, CAD, CHF, NZD, THB, and MYR.

= How do refunds work? =

Refunds can be processed in two ways:
1. **Automatic** - Enable auto-refund, and cancelling orders triggers instant refunds
2. **Manual** - Process refunds from FluentCart admin panel

Both full and partial refunds are supported.

= Is the plugin translation ready? =

Yes, the plugin is fully internationalized with a POT file included. Chinese and English translations are built-in. You can translate to any language using tools like Poedit.

= What happens if webhook notifications fail? =

The plugin includes a payment status checker that polls Alipay for payment confirmation if webhooks fail. This ensures reliable payment processing even with network issues.

= Can I customize the payment flow? =

Yes! The plugin provides numerous hooks and filters for customization:
- Modify payment parameters
- Custom notify/return URLs
- Custom payment subject lines
- Integration with your custom workflows

= Does it work on mobile devices? =

Yes! The plugin automatically detects mobile devices and uses Alipay's mobile WAP payment interface for the best mobile experience.

= What about Face-to-Face payments? =

Enable QR code payments in settings. Customers can scan QR codes with their Alipay app for offline/in-store payments.

== Screenshots ==

1. Alipay payment gateway in FluentCart payment list
2. Alipay payment gateway settings page with all configuration options
3. Payment method selection during checkout
4. Desktop Face-to-Face QR code payment page
5. Payment receipt

== Changelog ==

= 1.0.5 =
Release Date: October 22, 2025

* Added: Translator comments for all placeholder strings
* Added: Comprehensive POT translation template
* Improved: Subscription cancellation sync with order cancellation
* Improved: UTF-8 encoding handling for Chinese characters
* Improved: Face-to-Face payment amount formatting
* Fixed: Order number display in payment subject
* Fixed: Multiple placeholder string ordering for proper translation
* Updated: Documentation with detailed configuration guide
* Performance: Optimized webhook processing

= 1.0.4 =
Release Date: October 21, 2025

* Added: Automatic subscription cancellation when parent order is cancelled
* Added: Configurable subscription cancellation behavior
* Improved: Recurring agreement handling
* Improved: Error messages and logging
* Fixed: Subscription status synchronization issues

= 1.0.3 =
Release Date: October 20, 2025

* Added: Face-to-Face QR code payment support
* Added: Enhanced mobile device detection
* Fixed: UTF-8 encoding issues with Chinese characters
* Fixed: Subject line truncation for long product names
* Improved: Payment page rendering performance

= 1.0.2 =
Release Date: October 18, 2025

* Added: Automatic refund feature
* Added: Refund activity logging
* Improved: Webhook security validation
* Fixed: Amount mismatch detection
* Fixed: Timezone issues with Beijing time

= 1.0.1 =
Release Date: October 16, 2025

* Added: Full subscription payment support
* Added: Recurring agreement integration
* Added: Trial period handling
* Improved: Error handling and logging
* Fixed: Cart session cleanup for repeat purchases

= 1.0.0 =
Release Date: October 15, 2025

* Initial release
* Multi-platform payment support (PC, Mobile, App)
* Automatic client detection
* Basic refund functionality
* Multi-currency support
* Test mode/Sandbox support
* Webhook notification handling
* i18n/l10n ready

== Upgrade Notice ==

= 1.0.5 =
This version adds comprehensive translation support and improves subscription handling. Recommended upgrade for all users.

= 1.0.4 =
Important update for subscription users. Adds automatic subscription cancellation sync with orders.

= 1.0.3 =
Adds Face-to-Face QR payment and fixes encoding issues. Recommended for merchants in China.

= 1.0.0 =
Initial release of WPKJ FluentCart Alipay Payment gateway.

== Support and Documentation ==

For comprehensive documentation, tutorials, and support:

* **Official Website**: [https://www.wpdaxue.com](https://www.wpdaxue.com)
* **Documentation**: [https://www.wpdaxue.com/wpkj-fluentcart-alipay-payment](https://www.wpdaxue.com/wpkj-fluentcart-alipay-payment)
* **Support Email**: support@wwpdaxue.com
* **GitHub**: Report issues and contribute

== Privacy and Data ==

This plugin does NOT:
- Collect any user data
- Send data to third parties (except Alipay for payment processing)
- Track users
- Store sensitive payment information

Payment data is transmitted securely via HTTPS directly to Alipay's servers. The plugin only stores transaction IDs and order metadata necessary for order fulfillment.

== Credits ==

* Developed by: cmhello
* Contributors: WordPress community
* Framework: FluentCart Payment Gateway API
* Special Thanks: FluentCart team for excellent payment framework
