<?php
/**
 * View Alipay Notification Logs
 * 
 * Access: https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/view-logs.php
 */

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied');
}

$log_file = __DIR__ . '/alipay-notifications.log';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Alipay Notification Logs</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #f5f5f5; }
        .controls { background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd; }
        .controls button { padding: 8px 15px; margin-right: 10px; cursor: pointer; }
        .log-content { background: #fff; padding: 15px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word; max-height: 80vh; overflow-y: auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
    </style>
</head>
<body>
    <h1>Alipay Notification Logs</h1>
    
    <div class="controls">
        <button onclick="location.reload()">🔄 Refresh</button>
        <button onclick="clearLogs()">🗑️ Clear Logs</button>
        <button onclick="downloadLogs()">📥 Download</button>
        <span class="info">Log file: <?php echo esc_html(basename($log_file)); ?></span>
    </div>
    
    <div class="log-content">
<?php
if (file_exists($log_file)) {
    $content = file_get_contents($log_file);
    if (!empty($content)) {
        echo '<span class="success">✓ Logs found (' . number_format(strlen($content)) . ' bytes)</span>' . "\n\n";
        echo esc_html($content);
    } else {
        echo '<span class="info">ℹ️ Log file exists but is empty. No notifications received yet.</span>';
    }
} else {
    echo '<span class="error">✗ Log file not found. No notifications have been received yet.</span>';
}
?>
    </div>
    
    <script>
    function clearLogs() {
        if (confirm('Are you sure you want to clear all logs?')) {
            fetch('?action=clear_logs')
                .then(() => location.reload());
        }
    }
    
    function downloadLogs() {
        window.open('?action=download_logs', '_blank');
    }
    </script>
    
    <?php
    // Handle actions
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'clear_logs' && file_exists($log_file)) {
            file_put_contents($log_file, '');
            echo '<meta http-equiv="refresh" content="0">';
        }
        
        if ($_GET['action'] === 'download_logs' && file_exists($log_file)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="alipay-notifications-' . date('Y-m-d-His') . '.log"');
            readfile($log_file);
            exit;
        }
    }
    ?>
</body>
</html>
