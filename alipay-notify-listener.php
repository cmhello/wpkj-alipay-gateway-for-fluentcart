<?php
/**
 * Alipay Notification Listener (Standalone)
 * 
 * This file logs all POST requests to help debug notification issues.
 * Place this at: https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/alipay-notify-listener.php
 * 
 * Configure in Alipay as:
 * https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/alipay-notify-listener.php
 */

// Log file path
$log_file = __DIR__ . '/alipay-notifications.log';

// Get current time
$time = date('Y-m-d H:i:s');

// Log the request
$log_entry = "\n" . str_repeat('=', 80) . "\n";
$log_entry .= "Time: {$time}\n";
$log_entry .= "Method: {$_SERVER['REQUEST_METHOD']}\n";
$log_entry .= "IP: {$_SERVER['REMOTE_ADDR']}\n";
$log_entry .= "User Agent: {$_SERVER['HTTP_USER_AGENT']}\n";
$log_entry .= "\nPOST Data:\n";
$log_entry .= print_r($_POST, true);
$log_entry .= "\nGET Data:\n";
$log_entry .= print_r($_GET, true);
$log_entry .= "\nRaw Input:\n";
$log_entry .= file_get_contents('php://input');
$log_entry .= "\n" . str_repeat('=', 80) . "\n";

// Write to log
file_put_contents($log_file, $log_entry, FILE_APPEND);

// If this is a POST request (actual notification), forward to FluentCart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    // Load WordPress
    require_once(__DIR__ . '/../../../wp-load.php');
    
    // Trigger FluentCart's IPN handler
    do_action('fluent_cart_action_fct_payment_listener_ipn', [
        'method' => 'alipay',
        'data' => $_POST
    ]);
    
    // Log that we forwarded the request
    file_put_contents($log_file, "\n>>> Forwarded to FluentCart IPN handler\n", FILE_APPEND);
    
    // Return success to Alipay
    echo 'success';
} else {
    // Return info for GET requests (testing)
    header('Content-Type: text/plain; charset=utf-8');
    echo "Alipay Notification Listener\n";
    echo "============================\n\n";
    echo "This endpoint is ready to receive notifications.\n\n";
    echo "Configure this URL in Alipay:\n";
    echo "https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/alipay-notify-listener.php\n\n";
    echo "Logs are saved to: alipay-notifications.log\n";
    echo "Current time: {$time}\n";
}

exit;
