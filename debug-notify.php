<?php
/**
 * Alipay Notification Debug Tool
 * 
 * Place this file in plugin root and access via:
 * https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/debug-notify.php
 * 
 * This will help diagnose why notifications are not being processed.
 */

// Load WordPress
// Path: /wp-content/plugins/wpkj-fluentcart-alipay-payment/debug-notify.php
// Need to go up 3 levels to reach WordPress root
require_once(__DIR__ . '/../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Alipay Notification Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Alipay Payment Notification Debug Tool</h1>
    
    <?php
    use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
    use WPKJFluentCart\Alipay\Utils\Logger;
    
    // 1. Check if plugin is loaded
    echo '<div class="section">';
    echo '<h2>1. Plugin Status</h2>';
    if (defined('WPKJ_FC_ALIPAY_VERSION')) {
        echo '<p class="success">✓ Plugin loaded (Version: ' . WPKJ_FC_ALIPAY_VERSION . ')</p>';
    } else {
        echo '<p class="error">✗ Plugin not loaded</p>';
    }
    echo '</div>';
    
    // 2. Check settings
    echo '<div class="section">';
    echo '<h2>2. Gateway Settings</h2>';
    $settings = new AlipaySettingsBase();
    $mode = $settings->get('payment_mode', 'test');
    $isActive = $settings->get('is_active');
    
    echo '<table>';
    echo '<tr><th>Setting</th><th>Value</th></tr>';
    echo '<tr><td>Is Active</td><td>' . ($isActive === 'yes' ? '<span class="success">Yes</span>' : '<span class="error">No</span>') . '</td></tr>';
    echo '<tr><td>Payment Mode</td><td>' . ucfirst($mode) . '</td></tr>';
    echo '<tr><td>App ID</td><td>' . ($settings->get("{$mode}_app_id") ? '<span class="success">Configured</span>' : '<span class="error">Not set</span>') . '</td></tr>';
    echo '<tr><td>Private Key</td><td>' . ($settings->get("{$mode}_private_key") ? '<span class="success">Configured</span>' : '<span class="error">Not set</span>') . '</td></tr>';
    echo '<tr><td>Alipay Public Key</td><td>' . ($settings->get("{$mode}_alipay_public_key") ? '<span class="success">Configured</span>' : '<span class="error">Not set</span>') . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    // 3. Check FluentCart actions
    echo '<div class="section">';
    echo '<h2>3. FluentCart IPN Action</h2>';
    
    $ipn_action = 'fluent_cart_action_fct_payment_listener_ipn';
    if (has_action($ipn_action)) {
        global $wp_filter;
        $callbacks = isset($wp_filter[$ipn_action]) ? count($wp_filter[$ipn_action]->callbacks) : 0;
        echo '<p class="success">✓ Action ' . $ipn_action . ' is registered (' . $callbacks . ' priority levels)</p>';
        
        // Show callbacks
        if (isset($wp_filter[$ipn_action])) {
            echo '<h4>Registered Callbacks:</h4><pre>';
            foreach ($wp_filter[$ipn_action]->callbacks as $priority => $callbacks) {
                echo "Priority $priority:\n";
                foreach ($callbacks as $callback) {
                    if (is_array($callback['function'])) {
                        if (is_object($callback['function'][0])) {
                            echo "  - " . get_class($callback['function'][0]) . "::" . $callback['function'][1] . "\n";
                        } else {
                            echo "  - " . $callback['function'][0] . "::" . $callback['function'][1] . "\n";
                        }
                    } else {
                        echo "  - " . print_r($callback['function'], true) . "\n";
                    }
                }
            }
            echo '</pre>';
        }
    } else {
        echo '<p class="error">✗ Action ' . $ipn_action . ' is NOT registered</p>';
        echo '<p class="warning">This means FluentCart\'s GlobalPaymentHandler is not loaded properly!</p>';
    }
    echo '</div>';
    
    // 4. Check notify URL
    echo '<div class="section">';
    echo '<h2>4. Notify URL Configuration</h2>';
    $notify_url = add_query_arg([
        'fct_payment_listener' => '1',
        'method' => 'alipay'
    ], site_url('/'));
    
    echo '<p><strong>Your Notify URL:</strong></p>';
    echo '<pre>' . esc_html($notify_url) . '</pre>';
    echo '<p class="warning">⚠️ Make sure this EXACT URL is configured in your Alipay application settings.</p>';
    echo '</div>';
    
    // 5. Check recent logs
    echo '<div class="section">';
    echo '<h2>5. Recent Alipay Logs</h2>';
    
    global $wpdb;
    $logs = $wpdb->get_results("
        SELECT * FROM {$wpdb->prefix}fct_logs 
        WHERE title LIKE '%Alipay%' 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    
    if ($logs) {
        echo '<table>';
        echo '<tr><th>Time</th><th>Title</th><th>Level</th><th>Message</th></tr>';
        foreach ($logs as $log) {
            $level_class = $log->level === 'error' ? 'error' : ($log->level === 'warning' ? 'warning' : 'success');
            echo '<tr>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td>' . esc_html($log->title) . '</td>';
            echo '<td class="' . $level_class . '">' . esc_html($log->level) . '</td>';
            echo '<td><pre>' . esc_html(substr($log->message, 0, 200)) . (strlen($log->message) > 200 ? '...' : '') . '</pre></td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="warning">No Alipay logs found. This might indicate notifications are not reaching the server.</p>';
    }
    echo '</div>';
    
    // 6. Check recent pending transactions
    echo '<div class="section">';
    echo '<h2>6. Recent Pending Transactions</h2>';
    
    $transactions = $wpdb->get_results("
        SELECT t.*, o.uuid as order_uuid 
        FROM {$wpdb->prefix}fct_order_transactions t
        LEFT JOIN {$wpdb->prefix}fct_orders o ON t.order_id = o.id
        WHERE t.payment_method = 'alipay' 
        AND t.status = 'pending'
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    
    if ($transactions) {
        echo '<table>';
        echo '<tr><th>Transaction UUID</th><th>Order UUID</th><th>Amount</th><th>Status</th><th>Created</th></tr>';
        foreach ($transactions as $txn) {
            echo '<tr>';
            echo '<td>' . esc_html($txn->uuid) . '</td>';
            echo '<td>' . esc_html($txn->order_uuid) . '</td>';
            echo '<td>' . esc_html($txn->total) . '</td>';
            echo '<td class="warning">' . esc_html($txn->status) . '</td>';
            echo '<td>' . esc_html($txn->created_at) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>No pending Alipay transactions found.</p>';
    }
    echo '</div>';
    
    // 7. Test notification simulation
    echo '<div class="section">';
    echo '<h2>7. Test Notification Simulation</h2>';
    echo '<p>You can use this form to simulate an Alipay notification to test your handler:</p>';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_notify'])) {
        echo '<h4>Test Result:</h4>';
        
        $test_data = [
            'notify_time' => date('Y-m-d H:i:s'),
            'notify_type' => 'trade_status_sync',
            'notify_id' => 'test_' . time(),
            'app_id' => $settings->get("{$mode}_app_id"),
            'charset' => 'UTF-8',
            'version' => '1.0',
            'sign_type' => 'RSA2',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '0.01',
            'out_trade_no' => $_POST['out_trade_no'],
            'trade_no' => '2024' . time() . rand(1000, 9999),
            'buyer_logon_id' => 'test@sandbox.com',
            'buyer_user_id' => '2088000000000000',
            'gmt_payment' => date('Y-m-d H:i:s'),
        ];
        
        echo '<pre>';
        echo "Test notification data:\n";
        print_r($test_data);
        
        try {
            $notifyHandler = new \WPKJFluentCart\Alipay\Webhook\NotifyHandler();
            // Note: This won't actually process because signature will fail
            echo "\n\n⚠️ Note: Actual processing will fail signature verification.";
            echo "\nTo test properly, use Alipay's sandbox testing tools.";
        } catch (Exception $e) {
            echo "\n\nError: " . $e->getMessage();
        }
        echo '</pre>';
    }
    
    ?>
    <form method="POST">
        <label>Out Trade No (Transaction UUID without dashes):</label><br>
        <input type="text" name="out_trade_no" size="50" placeholder="e.g., 6f3a8b2c4d5e6f7a8b9c0d1e2f3a4b5c" required><br><br>
        <button type="submit" name="test_notify">Test Notification Handler</button>
    </form>
    <?php
    echo '</div>';
    
    // 8. Recommendations
    echo '<div class="section">';
    echo '<h2>8. Troubleshooting Recommendations</h2>';
    echo '<ul>';
    
    if ($isActive !== 'yes') {
        echo '<li class="error">Enable the Alipay gateway in FluentCart settings</li>';
    }
    
    if (!has_action($ipn_action)) {
        echo '<li class="error">FluentCart GlobalPaymentHandler is not loaded. Check if FluentCart is active and updated.</li>';
    }
    
    echo '<li>Make sure the notify URL in Alipay matches EXACTLY: <code>' . esc_html($notify_url) . '</code></li>';
    echo '<li>Check Alipay sandbox notification settings (应用网关地址)</li>';
    echo '<li>Use Alipay\'s "网关测试" tool to send test notifications</li>';
    echo '<li>Check WordPress debug.log for errors</li>';
    echo '<li>Verify domain configuration (should be waas.wpdaxue.com, not www.wpdaxue.com)</li>';
    echo '</ul>';
    echo '</div>';
    ?>
    
    <p style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd; color: #666;">
        <em>Debug tool version 1.0 - <?php echo date('Y-m-d H:i:s'); ?></em>
    </p>
</body>
</html>
