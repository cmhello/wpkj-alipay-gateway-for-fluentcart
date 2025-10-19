=== WPKJ FluentCart Alipay Payment ===
Contributors: wpkjteam
Tags: fluentcart, alipay, payment gateway, woocommerce, ecommerce
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Alipay payment gateway integration for FluentCart - Support PC Web, Mobile WAP, and In-App payments.

== Description ==

WPKJ FluentCart Alipay Payment is a professional payment gateway plugin that seamlessly integrates Alipay with FluentCart. It automatically detects the user's device and selects the appropriate payment interface.

= Features =

* **Multi-Platform Support**: Automatically switch between PC Web, Mobile WAP, and Alipay App payment interfaces
* **Secure Payments**: RSA2 signature encryption for maximum security
* **Webhook Support**: Real-time payment notifications
* **Refund Support**: Process refunds directly from FluentCart admin
* **Test Mode**: Sandbox environment for testing before going live
* **Multi-Currency**: Support for CNY, USD, EUR, GBP, and more
* **Internationalization**: Fully translatable with .pot file included

= Supported Payment Methods =

* PC Web Payment (alipay.trade.page.pay)
* Mobile WAP Payment (alipay.trade.wap.pay)
* Alipay App Payment (alipay.trade.app.pay)

= Requirements =

* FluentCart 1.2.0 or higher
* WordPress 6.0 or higher
* PHP 8.2 or higher
* SSL Certificate (HTTPS) for production use

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wpkj-fluentcart-alipay-payment/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to FluentCart > Settings > Payments
4. Configure your Alipay credentials (App ID, Private Key, Alipay Public Key)
5. Enable the gateway and save settings

== Configuration ==

1. Log in to [Alipay Open Platform](https://open.alipay.com/)
2. Create or select your application
3. Obtain your App ID
4. Generate RSA2 key pair and upload public key to Alipay
5. Copy Alipay's public key
6. Enter credentials in plugin settings
7. Configure the Notify URL in your Alipay application settings

== Frequently Asked Questions ==

= Where can I get Alipay credentials? =

You need to register an account on [Alipay Open Platform](https://open.alipay.com/) and create an application to obtain the credentials.

= Does this plugin support test mode? =

Yes, you can use Alipay's sandbox environment for testing before going live.

= What currencies are supported? =

The plugin supports CNY, USD, EUR, GBP, HKD, JPY, KRW, SGD, AUD, CAD, CHF, NZD, THB, and MYR.

= Can I process refunds? =

Yes, you can process full or partial refunds directly from the FluentCart admin panel.

== Changelog ==

= 1.0.0 =
* Initial release
* Support for PC, Mobile WAP, and App payments
* Automatic client detection
* Webhook support
* Refund functionality
* Multi-currency support
* Test mode support

== Upgrade Notice ==

= 1.0.0 =
Initial release of WPKJ FluentCart Alipay Payment gateway.

== Screenshots ==

1. Alipay payment settings page
2. Payment method selection on checkout
3. Mobile payment interface
4. Transaction details in admin

== Support ==

For support and documentation, please visit [https://wpkj.com/support](https://wpkj.com/support)

== Credits ==

Developed by WPKJ Team
