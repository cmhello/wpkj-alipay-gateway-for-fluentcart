<?php
/**
 * Custom Payment API Test Examples
 * 
 * These examples demonstrate how to use the Custom Payment API
 * for both Alipay and WeChat Pay plugins.
 * 
 * @package WPKJ FluentCart Payment Plugins
 * @version 1.0.0
 */

// Example 1: Create a one-time payment order with Alipay
function test_create_alipay_onetime_payment() {
    $data = [
        'customer_email' => 'customer@example.com',
        'items' => [
            [
                'name' => 'WordPress 高级主题',
                'price' => 29900, // 299.00 元 (单位：分)
                'quantity' => 1,
                'payment_type' => 'onetime'
            ]
        ]
    ];

    $response = wp_remote_post(
        rest_url('wpkj-fc-alipay/v1/custom-payment/create'),
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ],
            'body' => json_encode($data)
        ]
    );

    if (is_wp_error($response)) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo '错误: ' . $response->get_error_message();
        return;
    }

    $result = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($result['status'] === 'success') {
        echo '订单创建成功!' . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Order Hash: ' . $result['data']['order_hash'] . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Payment URL: ' . $result['data']['payment_url'] . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Total Amount: ' . ($result['data']['total_amount'] / 100) . ' 元' . PHP_EOL;
    } else {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo '订单创建失败: ' . ($result['message'] ?? '未知错误');
    }
}

// Example 2: Create a subscription payment order with Alipay
function test_create_alipay_subscription() {
    $data = [
        'customer_email' => 'vip@example.com',
        'items' => [
            [
                'name' => 'VIP 会员月度订阅',
                'price' => 9900, // 99.00 元/月
                'quantity' => 1,
                'payment_type' => 'subscription',
                'subscription_info' => [
                    'signup_fee' => 0, // 无注册费
                    'times' => 0, // 无限次续费
                    'repeat_interval' => 'monthly' // 每月续费
                ]
            ]
        ]
    ];

    $response = wp_remote_post(
        rest_url('wpkj-fc-alipay/v1/custom-payment/create'),
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ],
            'body' => json_encode($data)
        ]
    );

    if (!is_wp_error($response)) {
        $result = json_decode(wp_remote_retrieve_body($response), true);
        print_r($result);
    }
}

// Example 3: Create a WeChat Pay payment order
function test_create_wechat_payment() {
    $data = [
        'customer_email' => 'wechat@example.com',
        'items' => [
            [
                'name' => 'WordPress 插件',
                'price' => 19900, // 199.00 元
                'quantity' => 2,
                'payment_type' => 'onetime'
            ]
        ]
    ];

    $response = wp_remote_post(
        rest_url('wpkj-fc-wechat/v1/custom-payment/create'),
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ],
            'body' => json_encode($data)
        ]
    );

    if (!is_wp_error($response)) {
        $result = json_decode(wp_remote_retrieve_body($response), true);
        print_r($result);
    }
}

// Example 4: Check payment status
function test_check_payment_status($order_hash, $gateway = 'alipay') {
    $endpoint = $gateway === 'alipay' 
        ? "wpkj-fc-alipay/v1/custom-payment/status/{$order_hash}"
        : "wpkj-fc-wechat/v1/custom-payment/status/{$order_hash}";

    $response = wp_remote_get(
        rest_url($endpoint),
        [
            'headers' => [
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ]
        ]
    );

    if (is_wp_error($response)) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo '错误: ' . $response->get_error_message();
        return;
    }

    $result = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($result['status'] === 'success') {
        $data = $result['data'];
        echo '订单状态查询成功!' . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Order Hash: ' . $data['order_hash'] . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Payment Status: ' . $data['payment_status'] . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Order Status: ' . $data['order_status'] . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Total Amount: ' . ($data['total_amount'] / 100) . ' 元' . PHP_EOL;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo 'Paid Amount: ' . ($data['paid_amount'] / 100) . ' 元' . PHP_EOL;
        echo 'Is Paid: ' . ($data['is_paid'] ? '是' : '否') . PHP_EOL;
    } else {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo '查询失败: ' . ($result['message'] ?? '未知错误');
    }
}

// Example 5: Create payment with multiple items
function test_create_multi_item_payment() {
    $data = [
        'customer_email' => 'multi@example.com',
        'items' => [
            [
                'name' => 'WordPress 主题 A',
                'price' => 15900,
                'quantity' => 1,
                'payment_type' => 'onetime'
            ],
            [
                'name' => 'WordPress 插件 B',
                'price' => 9900,
                'quantity' => 2,
                'payment_type' => 'onetime'
            ],
            [
                'name' => 'WordPress 教程 C',
                'price' => 4900,
                'quantity' => 3,
                'payment_type' => 'onetime'
            ]
        ]
    ];

    $response = wp_remote_post(
        rest_url('wpkj-fc-alipay/v1/custom-payment/create'),
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ],
            'body' => json_encode($data)
        ]
    );

    if (!is_wp_error($response)) {
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($result['status'] === 'success') {
            // 计算总金额
            $total = (15900 * 1) + (9900 * 2) + (4900 * 3);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
            echo "预期总金额: " . ($total / 100) . " 元" . PHP_EOL;
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
            echo "实际总金额: " . ($result['data']['total_amount'] / 100) . " 元" . PHP_EOL;
        }
        
        print_r($result);
    }
}

// Example 6: Using PaymentIntent and PaymentItem classes directly
function test_using_payment_classes() {
    // 创建 PaymentIntent
    $paymentIntent = new \FluentCart\App\Services\CustomPayment\PaymentIntent();
    $paymentIntent->setCustomerEmail('direct@example.com');

    // 创建 PaymentItem
    $item1 = new \FluentCart\App\Services\CustomPayment\PaymentItem();
    $item1->setItemName('专业服务')
          ->setPrice(50000) // 500.00 元
          ->setQuantity(1)
          ->setPaymentType('onetime');

    // 添加订阅商品
    $item2 = new \FluentCart\App\Services\CustomPayment\PaymentItem();
    $item2->setItemName('年度会员')
          ->setPrice(99900) // 999.00 元/年
          ->setQuantity(1)
          ->setPaymentType('subscription')
          ->setSubscriptionInfo([
              'signup_fee' => 0,
              'times' => 0,
              'repeat_interval' => 'yearly'
          ]);

    // 设置订单项
    $paymentIntent->setLineItems([$item1, $item2]);

    // 使用服务创建订单
    $service = new \WPKJFluentCart\Alipay\Services\CustomPaymentService();
    
    try {
        $result = $service->createPaymentOrder($paymentIntent);
        print_r($result);
    } catch (\Exception $e) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Example code for demonstration purposes
        echo '错误: ' . $e->getMessage();
    }
}

// Example 7: Custom permission check for API
function custom_api_permission_check() {
    // 为支付宝 API 添加自定义权限验证
    add_filter('wpkj_fc_alipay/custom_payment/check_permission', function($hasPermission, $request) {
        // 检查 API Key
        $apiKey = $request->get_header('X-API-Key');
        if ($apiKey === 'your-secret-api-key-here') {
            return true;
        }
        
        // 检查 IP 白名单
        $allowedIPs = ['192.168.1.100', '10.0.0.1'];
        $clientIP = $_SERVER['REMOTE_ADDR'];
        if (in_array($clientIP, $allowedIPs)) {
            return true;
        }
        
        // 保持原有权限检查
        return $hasPermission;
    }, 10, 2);

    // 为微信支付 API 添加相同的权限验证
    add_filter('wpkj_fc_wechat/custom_payment/check_permission', function($hasPermission, $request) {
        $apiKey = $request->get_header('X-API-Key');
        if ($apiKey === 'your-secret-api-key-here') {
            return true;
        }
        return $hasPermission;
    }, 10, 2);
}

// Example 8: Hook into order creation
function hook_custom_payment_order_creation() {
    // 为支付宝订单创建添加钩子
    add_filter('wpkj_fc_alipay/custom_payment/create_order', function($order, $orderData) {
        // 这里实现实际的订单创建逻辑
        // 示例：使用 FluentCart API 创建订单
        
        // 注意：这只是示例，实际实现需要根据 FluentCart API 调整
        try {
            // 创建订单逻辑...
            $createdOrder = [
                'id' => 123,
                'uuid' => 'abc-123-def-456',
                'total_amount' => $orderData['total']
            ];
            
            return $createdOrder;
        } catch (\Exception $e) {
            error_log('Failed to create order: ' . $e->getMessage());
            return null;
        }
    }, 10, 2);
}

/**
 * 使用说明：
 * 
 * 1. 将此文件放在你的主题或插件中
 * 2. 调用相应的测试函数来测试 API
 * 3. 确保已经配置好支付网关
 * 4. 根据实际情况修改测试数据
 * 
 * 运行测试示例：
 * - test_create_alipay_onetime_payment();
 * - test_create_alipay_subscription();
 * - test_create_wechat_payment();
 * - test_check_payment_status('your-order-hash', 'alipay');
 * - test_create_multi_item_payment();
 * - test_using_payment_classes();
 */

/**
 * REST API 使用示例（通过 JavaScript）
 */
?>
<script>
// JavaScript 示例：创建支付订单
async function createPaymentOrderJS() {
    const data = {
        customer_email: 'js@example.com',
        items: [
            {
                name: 'JavaScript 课程',
                price: 29900,
                quantity: 1,
                payment_type: 'onetime'
            }
        ]
    };

    try {
        const response = await fetch('/wp-json/wpkj-fc-alipay/v1/custom-payment/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce // 需要先 localize script
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        
        if (result.status === 'success') {
            console.log('订单创建成功！');
            console.log('支付链接:', result.data.payment_url);
            
            // 重定向到支付页面
            window.location.href = result.data.payment_url;
        } else {
            console.error('订单创建失败:', result.message);
        }
    } catch (error) {
        console.error('请求失败:', error);
    }
}

// JavaScript 示例：查询支付状态
async function checkPaymentStatusJS(orderHash) {
    try {
        const response = await fetch(
            `/wp-json/wpkj-fc-alipay/v1/custom-payment/status/${orderHash}`,
            {
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce
                }
            }
        );

        const result = await response.json();
        
        if (result.status === 'success') {
            console.log('订单状态:', result.data.payment_status);
            console.log('是否已支付:', result.data.is_paid);
            
            if (result.data.is_paid) {
                alert('支付成功！');
            }
        }
    } catch (error) {
        console.error('查询失败:', error);
    }
}
</script>
