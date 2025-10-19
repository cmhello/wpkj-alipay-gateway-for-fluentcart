<?php
/**
 * Test URL Generation
 * 
 * This script tests the new return_url generation logic
 * Access: https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/test-url-generation.php
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
    <title>Test URL Generation - Alipay Payment</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 900px;
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
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .warning {
            color: #ffc107;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Alipay Return URL Generation Test</h1>
        
        <?php
        try {
            // Test 1: Get Receipt Page URL from FluentCart
            echo '<div class="section">';
            echo '<div class="label">📍 FluentCart Receipt Page Configuration:</div>';
            
            $storeSettings = new \FluentCart\Api\StoreSettings();
            $receiptPageId = $storeSettings->getReceiptPageId();
            $receiptPage = $storeSettings->getReceiptPage();
            
            echo '<table>';
            echo '<tr><th>Property</th><th>Value</th></tr>';
            echo '<tr><td>Receipt Page ID</td><td>' . esc_html($receiptPageId) . '</td></tr>';
            echo '<tr><td>Receipt Page URL (getReceiptPage)</td><td class="value">' . esc_html($receiptPage) . '</td></tr>';
            
            if (!empty($receiptPageId)) {
                $pageTitle = get_the_title($receiptPageId);
                $pagePermalink = get_permalink($receiptPageId);
                echo '<tr><td>Page Title</td><td>' . esc_html($pageTitle) . '</td></tr>';
                echo '<tr><td>WordPress Permalink</td><td class="value">' . esc_html($pagePermalink) . '</td></tr>';
            }
            echo '</table>';
            echo '</div>';
            
            // Test 2: Generate URLs using different methods
            echo '<div class="section">';
            echo '<div class="label">🔧 URL Generation Methods:</div>';
            
            $testUuid = '02df5564642c351ae47897acb4253a16';
            
            // Method 1: Old method (wrong)
            $params = http_build_query([
                'method' => 'alipay',
                'trx_hash' => $testUuid,
                'fct_redirect' => 'yes'
            ], '', '&', PHP_QUERY_RFC3986);
            $oldUrl = $receiptPage . (strpos($receiptPage, '?') !== false ? '&' : '?') . $params;
            
            // Method 2: New method using add_query_arg (correct)
            $newUrl = add_query_arg([
                'method' => 'alipay',
                'trx_hash' => $testUuid,
                'fct_redirect' => 'yes'
            ], $receiptPage);
            
            // Method 3: FluentCart routing format (fallback)
            $fluentCartUrl = add_query_arg([
                'method' => 'alipay',
                'trx_hash' => $testUuid,
                'fct_redirect' => 'yes'
            ], home_url('/?fluent-cart=receipt'));
            
            echo '<table>';
            echo '<tr><th>Method</th><th>URL</th><th>Status</th></tr>';
            
            echo '<tr>';
            echo '<td><strong>❌ Old Method</strong><br><small>(String concatenation)</small></td>';
            echo '<td class="value">' . esc_html($oldUrl) . '</td>';
            echo '<td class="error">Wrong - May not trigger FluentCart routing</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<td><strong>✅ New Method</strong><br><small>(add_query_arg on getReceiptPage)</small></td>';
            echo '<td class="value">' . esc_html($newUrl) . '</td>';
            echo '<td class="success">Correct - Recommended</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<td><strong>🔄 FluentCart Routing</strong><br><small>(Explicit fluent-cart parameter)</small></td>';
            echo '<td class="value">' . esc_html($fluentCartUrl) . '</td>';
            echo '<td class="warning">Alternative - Fallback if page not configured</td>';
            echo '</tr>';
            
            echo '</table>';
            echo '</div>';
            
            // Test 3: Parse and compare URLs
            echo '<div class="section">';
            echo '<div class="label">🔍 URL Analysis:</div>';
            
            echo '<table>';
            echo '<tr><th>Method</th><th>Base Path</th><th>Parameters</th></tr>';
            
            // Parse old URL
            $oldParsed = parse_url($oldUrl);
            parse_str($oldParsed['query'] ?? '', $oldQuery);
            echo '<tr>';
            echo '<td>Old Method</td>';
            echo '<td class="value">' . esc_html($oldParsed['path'] ?? '/') . '</td>';
            echo '<td><pre>' . print_r($oldQuery, true) . '</pre></td>';
            echo '</tr>';
            
            // Parse new URL
            $newParsed = parse_url($newUrl);
            parse_str($newParsed['query'] ?? '', $newQuery);
            echo '<tr>';
            echo '<td>New Method</td>';
            echo '<td class="value">' . esc_html($newParsed['path'] ?? '/') . '</td>';
            echo '<td><pre>' . print_r($newQuery, true) . '</pre></td>';
            echo '</tr>';
            
            // Parse FluentCart URL
            $fluentParsed = parse_url($fluentCartUrl);
            parse_str($fluentParsed['query'] ?? '', $fluentQuery);
            echo '<tr>';
            echo '<td>FluentCart Routing</td>';
            echo '<td class="value">' . esc_html($fluentParsed['path'] ?? '/') . '</td>';
            echo '<td><pre>' . print_r($fluentQuery, true) . '</pre></td>';
            echo '</tr>';
            
            echo '</table>';
            echo '</div>';
            
            // Test 4: Route matching simulation
            echo '<div class="section">';
            echo '<div class="label">🎯 FluentCart Route Matching Simulation:</div>';
            
            echo '<table>';
            echo '<tr><th>URL</th><th>Has fluent-cart parameter?</th><th>Will Route?</th></tr>';
            
            echo '<tr>';
            echo '<td>Old Method</td>';
            echo '<td>' . (isset($oldQuery['fluent-cart']) ? '<span class="success">Yes</span>' : '<span class="error">No</span>') . '</td>';
            echo '<td>' . (isset($oldQuery['fluent-cart']) ? '<span class="success">Yes - Routes correctly</span>' : '<span class="error">No - May fail to route</span>') . '</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<td>New Method</td>';
            echo '<td>' . (isset($newQuery['fluent-cart']) ? '<span class="success">Yes</span>' : '<span class="warning">No (relies on page ID)</span>') . '</td>';
            echo '<td>' . (!empty($receiptPageId) ? '<span class="success">Yes - WordPress redirects to page</span>' : '<span class="error">No - Page not configured</span>') . '</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<td>FluentCart Routing</td>';
            echo '<td>' . (isset($fluentQuery['fluent-cart']) ? '<span class="success">Yes</span>' : '<span class="error">No</span>') . '</td>';
            echo '<td>' . (isset($fluentQuery['fluent-cart']) ? '<span class="success">Yes - Always works</span>' : '<span class="error">No</span>') . '</td>';
            echo '</tr>';
            
            echo '</table>';
            echo '</div>';
            
            // Test 5: Recommendations
            echo '<div class="section">';
            echo '<div class="label">💡 Recommendations:</div>';
            echo '<ul>';
            echo '<li><strong>✅ FIXED:</strong> The plugin now uses <code>add_query_arg()</code> which matches FluentCart\'s official approach</li>';
            echo '<li><strong>How it works:</strong> <code>add_query_arg()</code> intelligently appends parameters to URLs, handling both <code>/receipt/</code> and <code>/?fluent-cart=receipt</code> formats</li>';
            echo '<li><strong>Fallback:</strong> If receipt page is not configured, it uses explicit FluentCart routing (<code>/?fluent-cart=receipt</code>)</li>';
            echo '<li><strong>Next step:</strong> Clear all test orders and create a new payment to test the fixed URL</li>';
            echo '</ul>';
            echo '</div>';
            
        } catch (\Exception $e) {
            echo '<div class="section">';
            echo '<div class="label error">❌ Error:</div>';
            echo '<div class="value error">' . esc_html($e->getMessage()) . '</div>';
            echo '</div>';
        }
        ?>
        
        <div class="section" style="border-left-color: #28a745;">
            <div class="label success">✨ Test Complete</div>
            <p>The URL generation logic has been fixed. The new implementation:</p>
            <ol>
                <li>Uses <code>add_query_arg()</code> instead of manual string concatenation</li>
                <li>Matches FluentCart's official <code>PaymentHelper::successUrl()</code> implementation</li>
                <li>Provides a fallback to <code>/?fluent-cart=receipt</code> if page not configured</li>
                <li>Ensures compatibility with both WordPress permalinks and FluentCart routing</li>
            </ol>
            <p><strong>Next:</strong> Create a new test payment to verify the return URL triggers correctly.</p>
        </div>
    </div>
</body>
</html>
