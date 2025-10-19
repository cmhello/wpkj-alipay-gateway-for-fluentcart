<?php
/**
 * Plugin Name: WPKJ FluentCart Alipay Payment
 * Plugin URI: https://www.wpdaxue.com/fluentcart-alipay
 * Description: Alipay payment gateway integration for FluentCart - Support PC Web, Mobile WAP, and In-App payments
 * Version: 1.0.1
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

// Define plugin constants
define('WPKJ_FC_ALIPAY_VERSION', '1.0.1');
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
 */
function wpkj_fc_alipay_load_textdomain() {
    load_plugin_textdomain(
        'wpkj-fluentcart-alipay-payment',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}
add_action('plugins_loaded', 'wpkj_fc_alipay_load_textdomain');

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
    
    // Register the Alipay gateway with FluentCart
    // Must use 'init' hook because FluentCart registers gateways in 'init'
    add_action('init', function() {
        // Wait for FluentCart to trigger the registration hook
        add_action('fluent_cart/register_payment_methods', function($args) {
            $gatewayManager = $args['gatewayManager'];
            
            $alipayGateway = new \WPKJFluentCart\Alipay\Gateway\AlipayGateway();
            
            $gatewayManager->register('alipay', $alipayGateway);
            
        }, 10, 1);
    }, 9); // Priority 9 to run before FluentCart's init at priority 10
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
