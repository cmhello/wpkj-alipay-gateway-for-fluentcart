<?php
/**
 * Plugin Name: WPKJ FluentCart Alipay Payment
 * Plugin URI: https://www.wpdaxue.com/wpkj-fluentcart-alipay-payment
 * Description: Alipay payment gateway integration for FluentCart - Support PC Web, Mobile WAP, Face-to-Face, In-App payments, and Subscriptions
 * Version: 1.0.6
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: WPKJ Team
 * Author URI: https://www.wpdaxue.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpkj-fluentcart-alipay-payment
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('WPKJ_FC_ALIPAY_VERSION', '1.0.6');
define('WPKJ_FC_ALIPAY_FILE', __FILE__);
define('WPKJ_FC_ALIPAY_PATH', plugin_dir_path(__FILE__));
define('WPKJ_FC_ALIPAY_URL', plugin_dir_url(__FILE__));

/**
 * Check plugin dependencies
 */
function wpkj_fc_alipay_check_dependencies() {
    // Check if FluentCart is installed
    if (!class_exists('FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php echo esc_html__('WPKJ FluentCart Alipay Payment requires FluentCart to be installed and activated.', 'wpkj-fluentcart-alipay-payment'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    
    // Check FluentCart version
    if (defined('FLUENTCART_VERSION') && version_compare(FLUENTCART_VERSION, '1.2.0', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php echo esc_html__('WPKJ FluentCart Alipay Payment requires FluentCart version 1.2.0 or higher.', 'wpkj-fluentcart-alipay-payment'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    
    return true;
}

/**
 * Load plugin textdomain
 * 
 * Note: When hosted on WordPress.org, load_plugin_textdomain() is no longer required
 * as WordPress automatically loads translations. However, we keep it for compatibility
 * with manual installations and custom translation workflows.
 */
function wpkj_fc_alipay_load_textdomain() {
    load_plugin_textdomain( // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions -- Required for manual installations
        'wpkj-fluentcart-alipay-payment',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}
add_action('init', 'wpkj_fc_alipay_load_textdomain');

/**
 * Initialize autoloader
 */
spl_autoload_register(function($class) {
    $prefix = 'WPKJFluentCart\\Alipay\\';
    $base_dir = WPKJ_FC_ALIPAY_PATH . 'src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Bootstrap the plugin
 */
function wpkj_fc_alipay_bootstrap() {
    if (!wpkj_fc_alipay_check_dependencies()) {
        return;
    }
    
    add_action('init', function() {
        add_action('fluent_cart/register_payment_methods', function($args) {
            $gatewayManager = $args['gatewayManager'];
            
            $alipayGateway = new \WPKJFluentCart\Alipay\Gateway\AlipayGateway();
            
            $gatewayManager->register('alipay', $alipayGateway);
            
        }, 10, 1);
        
        $refundProcessor = new \WPKJFluentCart\Alipay\Processor\RefundProcessor();
        $refundProcessor->register();
        
        $statusChecker = new \WPKJFluentCart\Alipay\Processor\PaymentStatusChecker();
        $statusChecker->register();
        
        $f2fPageHandler = new \WPKJFluentCart\Alipay\Processor\FaceToFacePageHandler();
        $f2fPageHandler->register();
        
        // Register order cancel listener (for subscription cancellation sync)
        $orderCancelListener = new \WPKJFluentCart\Alipay\Listeners\OrderCancelListener();
        $orderCancelListener->register();
        
        // Initialize subscription renewal retry mechanism
        \WPKJFluentCart\Alipay\Subscription\SubscriptionRenewer::init();
        
    }, 9);
}
add_action('plugins_loaded', 'wpkj_fc_alipay_bootstrap', 20);

/**
 * Plugin activation hook
 */
function wpkj_fc_alipay_activate() {
    if (!wpkj_fc_alipay_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('WPKJ FluentCart Alipay Payment requires FluentCart to be installed and activated.', 'wpkj-fluentcart-alipay-payment'),
            esc_html__('Plugin Activation Error', 'wpkj-fluentcart-alipay-payment'),
            array('back_link' => true)
        );
    }
}
register_activation_hook(__FILE__, 'wpkj_fc_alipay_activate');
