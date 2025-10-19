<?php
/**
 * Test Alipay Return URL Handler
 * 
 * This page simulates Alipay's return and tests if ReturnHandler is triggered correctly
 * Access: https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/test-return-url.php
 */

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

// Only allow admin access
if (!current_user_can('manage_options')) {
    wp_die('Access denied');
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Alipay Return URL - 支付宝返回URL测试</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1000px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1678FF;
            margin-top: 0;
        }
        .section {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border-left: 4px solid #1678FF;
        }
        .label {
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
        }
        .value {
            font-family: 'Courier New', monospace;
            background: #fff;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            word-break: break-all;
            font-size: 12px;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .warning {
            color: #ffc107;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table th, table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #f1f1f1;
            font-weight: bold;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #1678FF;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 5px;
        }
        .btn:hover {
            background: #0d5ccc;
        }
        .code-block {
            background: #282c34;
            color: #abb2bf;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 支付宝返回 URL 测试</h1>
        
        <?php
        // Get latest transaction for testing
        $latestTrx = \FluentCart\App\Models\OrderTransaction::query()
            ->where('payment_method', 'alipay')
            ->orderBy('id', 'DESC')
            ->first();
        
        if (!$latestTrx) {
            echo '<div class="section">';
            echo '<div class="label error">❌ 错误</div>';
            echo '<p>没有找到支付宝交易记录。请先创建一个测试订单。</p>';
            echo '</div>';
        } else {
            echo '<div class="section">';
            echo '<div class="label">📊 最新的支付宝交易</div>';
            echo '<table>';
            echo '<tr><th>属性</th><th>值</th></tr>';
            echo '<tr><td>Transaction UUID</td><td class="value">' . esc_html($latestTrx->uuid) . '</td></tr>';
            echo '<tr><td>Order ID</td><td>' . esc_html($latestTrx->order_id) . '</td></tr>';
            echo '<tr><td>Status</td><td>' . esc_html($latestTrx->status) . '</td></tr>';
            echo '<tr><td>Amount</td><td>' . esc_html($latestTrx->total / 100) . ' CNY</td></tr>';
            echo '<tr><td>Created</td><td>' . esc_html($latestTrx->created_at) . '</td></tr>';
            echo '</table>';
            echo '</div>';
            
            // Simulate Alipay return URL
            echo '<div class="section">';
            echo '<div class="label">🔗 模拟支付宝返回 URL</div>';
            
            $testUrl = add_query_arg([
                'trx_hash' => $latestTrx->uuid,
                'fct_redirect' => 'yes',
                // Simulate Alipay's additional parameters
                'charset' => 'UTF-8',
                'out_trade_no' => $latestTrx->uuid,
                'method' => 'alipay.trade.page.pay.return',  // This overwrites our method=alipay!
                'total_amount' => ($latestTrx->total / 100),
                'sign' => 'test_signature_' . md5($latestTrx->uuid),
                'trade_no' => '2025' . date('mdHis') . rand(1000, 9999),
                'app_id' => '9021000156676178',
                'sign_type' => 'RSA2',
                'seller_id' => '2088721084863933',
                'timestamp' => date('Y-m-d H:i:s')
            ], site_url('/receipt/'));
            
            echo '<p><strong>测试 URL：</strong></p>';
            echo '<div class="value">' . esc_html($testUrl) . '</div>';
            
            echo '<p style="margin-top: 15px;"><strong>关键参数：</strong></p>';
            echo '<table>';
            echo '<tr><th>参数</th><th>值</th><th>说明</th></tr>';
            echo '<tr><td>trx_hash</td><td class="value">' . esc_html($latestTrx->uuid) . '</td><td>✅ 我们的交易ID</td></tr>';
            echo '<tr><td>fct_redirect</td><td class="value">yes</td><td>✅ 我们的返回标识</td></tr>';
            echo '<tr><td>method</td><td class="value">alipay.trade.page.pay.return</td><td>⚠️ 支付宝覆盖了我们的 method=alipay</td></tr>';
            echo '<tr><td>sign</td><td class="value">test_signature_...</td><td>✅ 支付宝签名（用于识别）</td></tr>';
            echo '<tr><td>out_trade_no</td><td class="value">' . esc_html($latestTrx->uuid) . '</td><td>✅ 商户订单号</td></tr>';
            echo '</table>';
            
            echo '<div style="margin-top: 20px;">';
            echo '<a href="' . esc_url($testUrl) . '" class="btn" target="_blank">🚀 点击测试返回处理器</a>';
            echo '</div>';
            
            echo '<p style="margin-top: 15px;"><strong>预期行为：</strong></p>';
            echo '<ul>';
            echo '<li>✅ 检测到 <code>trx_hash</code> 和 <code>fct_redirect=yes</code></li>';
            echo '<li>✅ 检测到支付宝参数 <code>sign</code> 或 <code>out_trade_no</code></li>';
            echo '<li>✅ 触发 ReturnHandler</li>';
            echo '<li>✅ 记录 "Alipay Return Detected" 日志</li>';
            echo '<li>✅ 记录 "Return URL Triggered" 日志</li>';
            echo '<li>✅ 调用 alipay.trade.query API 查询支付状态</li>';
            echo '<li>✅ 如果支付成功，更新订单状态</li>';
            echo '</ul>';
            
            echo '</div>';
            
            // Code explanation
            echo '<div class="section">';
            echo '<div class="label">💡 修复说明</div>';
            
            echo '<p><strong>问题：</strong></p>';
            echo '<p>支付宝在返回时会附加自己的参数，包括 <code>method=alipay.trade.page.pay.return</code>，这会覆盖我们设置的 <code>method=alipay</code> 参数。</p>';
            
            echo '<p style="margin-top: 15px;"><strong>原来的检测逻辑（有问题）：</strong></p>';
            echo '<div class="code-block">';
            echo '<pre>if (isset($_GET[\'method\']) && $_GET[\'method\'] === \'alipay\' &&
    isset($_GET[\'trx_hash\']) && isset($_GET[\'fct_redirect\'])) {
    // 触发处理器
}</pre>';
            echo '</div>';
            echo '<p class="error">❌ 问题：$_GET[\'method\'] === \'alipay\' 永远不会成立，因为被覆盖为 \'alipay.trade.page.pay.return\'</p>';
            
            echo '<p style="margin-top: 15px;"><strong>修复后的检测逻辑：</strong></p>';
            echo '<div class="code-block">';
            echo '<pre>if (isset($_GET[\'trx_hash\']) && 
    isset($_GET[\'fct_redirect\']) && 
    $_GET[\'fct_redirect\'] === \'yes\') {
    
    // 额外检查：必须有支付宝签名参数
    if (isset($_GET[\'sign\']) || isset($_GET[\'out_trade_no\'])) {
        // 触发处理器
    }
}</pre>';
            echo '</div>';
            echo '<p class="success">✅ 解决：不再依赖 method 参数，使用 trx_hash + fct_redirect + 支付宝特征参数判断</p>';
            
            echo '</div>';
            
            // Log check
            echo '<div class="section">';
            echo '<div class="label">📝 日志检查</div>';
            
            $debugLog = '/www/wwwroot/waas.wpdaxue.com/wp-content/debug.log';
            if (file_exists($debugLog)) {
                $logContent = file_get_contents($debugLog);
                $lines = explode("\n", $logContent);
                $relevantLogs = [];
                
                foreach ($lines as $line) {
                    if (stripos($line, 'alipay return detected') !== false ||
                        stripos($line, 'return url triggered') !== false ||
                        stripos($line, 'query trade') !== false ||
                        stripos($line, 'payment confirmed') !== false) {
                        $relevantLogs[] = $line;
                    }
                }
                
                if (!empty($relevantLogs)) {
                    echo '<p class="success">✅ 找到相关日志：</p>';
                    echo '<div class="value">';
                    foreach (array_slice($relevantLogs, -10) as $log) {
                        echo esc_html($log) . "\n";
                    }
                    echo '</div>';
                } else {
                    echo '<p class="warning">⚠️ 暂未找到返回处理相关日志，请点击上面的测试按钮。</p>';
                }
            } else {
                echo '<p class="warning">⚠️ debug.log 文件不存在</p>';
            }
            
            echo '</div>';
        }
        ?>
        
        <div class="section" style="border-left-color: #28a745;">
            <div class="label success">✅ 操作步骤</div>
            <ol>
                <li>点击上面的 "🚀 点击测试返回处理器" 按钮</li>
                <li>观察是否跳转到 Receipt 页面</li>
                <li>刷新本页面查看日志更新</li>
                <li>检查 FluentCart 后台订单状态是否更新</li>
            </ol>
            <p><strong>如果测试成功</strong>，再创建一个真实的支付订单进行验证。</p>
        </div>
        
        <div class="section">
            <div class="label">🔧 其他工具</div>
            <a href="test-url-generation.php" class="btn">URL 生成测试</a>
            <a href="debug-notify.php" class="btn">IPN 配置诊断</a>
            <a href="view-logs.php" class="btn">查看日志</a>
            <a href="../../../wp-admin/admin.php?page=fluent-cart#/orders" class="btn">FluentCart 订单</a>
        </div>
    </div>
</body>
</html>
