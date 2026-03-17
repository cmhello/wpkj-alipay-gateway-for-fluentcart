# Custom Payment API 使用指南

## 概述

FluentCart 支付宝插件提供了 Custom Payment API，允许外部系统通过 REST API 创建支付订单，并通过支付宝网关完成支付。

## 功能特性

- ✅ 支持一次性支付
- ✅ 支持订阅支付
- ✅ RESTful API 接口
- ✅ 完整的订单状态查询
- ✅ 安全的权限验证
- ✅ 详细的日志记录

## API 端点

### 1. 创建支付订单

**端点:** `POST /wp-json/wpkj-fc-alipay/v1/custom-payment/create`

**权限要求:** 需要 `manage_options` 权限（可通过过滤器自定义）

**请求参数:**

```json
{
  "customer_email": "customer@example.com",
  "items": [
    {
      "name": "产品名称",
      "price": 9900,
      "quantity": 1,
      "payment_type": "onetime"
    }
  ]
}
```

**参数说明:**

- `customer_email` (必填): 客户邮箱地址
- `items` (必填): 订单项数组
  - `name` (必填): 产品名称
  - `price` (必填): 价格（单位：分，例如 9900 = 99.00 元）
  - `quantity` (可选): 数量，默认为 1
  - `payment_type` (可选): 支付类型，`onetime` 或 `subscription`，默认为 `onetime`
  - `subscription_info` (订阅时必填): 订阅信息
    - `signup_fee` (必填): 注册费（单位：分）
    - `times` (必填): 订阅次数，0 表示无限次
    - `repeat_interval` (必填): 重复间隔，如 `monthly`, `yearly`

**响应示例:**

```json
{
  "status": "success",
  "message": "Payment order created successfully",
  "data": {
    "order_id": 123,
    "order_hash": "abc123def456",
    "payment_url": "https://yoursite.com/?fluent-cart=custom_checkout&order_hash=abc123def456",
    "total_amount": 9900
  }
}
```

### 2. 查询支付状态

**端点:** `GET /wp-json/wpkj-fc-alipay/v1/custom-payment/status/{order_hash}`

**权限要求:** 需要 `manage_options` 权限（可通过过滤器自定义）

**响应示例:**

```json
{
  "status": "success",
  "data": {
    "order_hash": "abc123def456",
    "payment_status": "paid",
    "order_status": "completed",
    "total_amount": 9900,
    "paid_amount": 9900,
    "is_paid": true
  }
}
```

## 使用示例

### PHP 示例 - 创建一次性支付订单

```php
<?php
// 准备请求数据
$data = [
    'customer_email' => 'customer@example.com',
    'items' => [
        [
            'name' => 'WordPress 高级主题',
            'price' => 29900, // 299.00 元
            'quantity' => 1,
            'payment_type' => 'onetime'
        ]
    ]
];

// 发送请求
$response = wp_remote_post(
    home_url('/wp-json/wpkj-fc-alipay/v1/custom-payment/create'),
    [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $your_auth_token
        ],
        'body' => json_encode($data)
    ]
);

if (!is_wp_error($response)) {
    $result = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($result['status'] === 'success') {
        $paymentUrl = $result['data']['payment_url'];
        // 重定向用户到支付页面
        wp_redirect($paymentUrl);
        exit;
    }
}
```

### PHP 示例 - 创建订阅支付订单

```php
<?php
$data = [
    'customer_email' => 'customer@example.com',
    'items' => [
        [
            'name' => 'WordPress 会员订阅',
            'price' => 9900, // 每月 99.00 元
            'quantity' => 1,
            'payment_type' => 'subscription',
            'subscription_info' => [
                'signup_fee' => 0, // 无注册费
                'times' => 0, // 无限次订阅
                'repeat_interval' => 'monthly' // 每月订阅
            ]
        ]
    ]
];

$response = wp_remote_post(
    home_url('/wp-json/wpkj-fc-alipay/v1/custom-payment/create'),
    [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $your_auth_token
        ],
        'body' => json_encode($data)
    ]
);
```

### JavaScript 示例

```javascript
// 创建支付订单
async function createPaymentOrder() {
  const data = {
    customer_email: 'customer@example.com',
    items: [
      {
        name: 'WordPress 插件',
        price: 19900, // 199.00 元
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
        'X-WP-Nonce': wpApiSettings.nonce
      },
      body: JSON.stringify(data)
    });

    const result = await response.json();
    
    if (result.status === 'success') {
      // 重定向到支付页面
      window.location.href = result.data.payment_url;
    }
  } catch (error) {
    console.error('创建订单失败:', error);
  }
}

// 查询支付状态
async function checkPaymentStatus(orderHash) {
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
      console.log('支付状态:', result.data.payment_status);
      console.log('是否已支付:', result.data.is_paid);
    }
  } catch (error) {
    console.error('查询状态失败:', error);
  }
}
```

## 自定义权限验证

你可以通过过滤器自定义 API 的权限验证逻辑：

```php
add_filter('wpkj_fc_alipay/custom_payment/check_permission', function($hasPermission, $request) {
    // 示例：允许特定 API Key 访问
    $apiKey = $request->get_header('X-API-Key');
    if ($apiKey === 'your-secret-api-key') {
        return true;
    }
    
    // 示例：允许特定 IP 地址访问
    $allowedIPs = ['192.168.1.100', '10.0.0.1'];
    $clientIP = $_SERVER['REMOTE_ADDR'];
    if (in_array($clientIP, $allowedIPs)) {
        return true;
    }
    
    return $hasPermission;
}, 10, 2);
```

## 订单创建钩子

Custom Payment Service 使用过滤器来创建 FluentCart 订单。你需要实现这个过滤器：

```php
add_filter('wpkj_fc_alipay/custom_payment/create_order', function($order, $orderData) {
    // 使用 FluentCart API 创建订单
    // 这里是示例实现，请根据实际情况调整
    
    $checkoutApi = new \FluentCart\Api\Checkout\CheckoutApi();
    
    // 准备订单数据
    $checkoutData = [
        'email' => $orderData['customer_email'],
        'items' => $orderData['items'],
        // 其他必要字段...
    ];
    
    // 创建订单
    $result = $checkoutApi->placeOrder($checkoutData);
    
    return $result['order'];
}, 10, 2);
```

## 支付流程

1. **创建订单**: 通过 API 创建支付订单
2. **获取支付链接**: API 返回支付链接
3. **跳转支付**: 引导用户到支付页面
4. **完成支付**: 用户通过支付宝完成支付
5. **支付回调**: 支付宝异步通知订单状态
6. **查询状态**: 通过 API 查询订单支付状态

## 注意事项

1. **价格单位**: 所有价格必须以"分"为单位，例如 100.00 元 = 10000 分
2. **权限安全**: 默认需要管理员权限，请根据需求自定义权限验证
3. **日志记录**: 所有操作都会记录日志，可在 FluentCart 日志中查看
4. **错误处理**: 请妥善处理 API 返回的错误信息
5. **HTTPS**: 生产环境建议使用 HTTPS 协议

## 支持的订阅间隔

- `daily` - 每天
- `weekly` - 每周
- `monthly` - 每月
- `quarterly` - 每季度
- `yearly` - 每年

## 故障排查

### 订单创建失败

1. 检查权限配置是否正确
2. 确认价格格式是否正确（必须是整数，单位为分）
3. 查看 FluentCart 日志获取详细错误信息

### 支付状态查询失败

1. 确认 order_hash 是否正确
2. 检查权限验证是否通过

## 更新日志

### Version 1.0.8
- 添加 Custom Payment API 支持
- 支持一次性支付和订阅支付
- 提供完整的 REST API 接口

## 技术支持

如有问题，请访问：https://www.wpdaxue.com
# Custom Payment API 使用指南

## 概述

FluentCart 支付宝插件提供了 Custom Payment API，允许外部系统通过 REST API 创建支付订单，并通过支付宝网关完成支付。

## 功能特性

- ✅ 支持一次性支付
- ✅ 支持订阅支付
- ✅ RESTful API 接口
- ✅ 完整的订单状态查询
- ✅ 安全的权限验证
- ✅ 详细的日志记录

## API 端点

### 1. 创建支付订单

**端点:** `POST /wp-json/wpkj-fc-alipay/v1/custom-payment/create`

**权限要求:** 需要 `manage_options` 权限（可通过过滤器自定义）

**请求参数:**

```json
{
  "customer_email": "customer@example.com",
  "items": [
    {
      "name": "产品名称",
      "price": 9900,
      "quantity": 1,
      "payment_type": "onetime"
    }
  ]
}
```

**参数说明:**

- `customer_email` (必填): 客户邮箱地址
- `items` (必填): 订单项数组
  - `name` (必填): 产品名称
  - `price` (必填): 价格（单位：分，例如 9900 = 99.00 元）
  - `quantity` (可选): 数量，默认为 1
  - `payment_type` (可选): 支付类型，`onetime` 或 `subscription`，默认为 `onetime`
  - `subscription_info` (订阅时必填): 订阅信息
    - `signup_fee` (必填): 注册费（单位：分）
    - `times` (必填): 订阅次数，0 表示无限次
    - `repeat_interval` (必填): 重复间隔，如 `monthly`, `yearly`

**响应示例:**

```json
{
  "status": "success",
  "message": "Payment order created successfully",
  "data": {
    "order_id": 123,
    "order_hash": "abc123def456",
    "payment_url": "https://yoursite.com/?fluent-cart=custom_checkout&order_hash=abc123def456",
    "total_amount": 9900
  }
}
```

### 2. 查询支付状态

**端点:** `GET /wp-json/wpkj-fc-alipay/v1/custom-payment/status/{order_hash}`

**权限要求:** 需要 `manage_options` 权限（可通过过滤器自定义）

**响应示例:**

```json
{
  "status": "success",
  "data": {
    "order_hash": "abc123def456",
    "payment_status": "paid",
    "order_status": "completed",
    "total_amount": 9900,
    "paid_amount": 9900,
    "is_paid": true
  }
}
```

## 使用示例

### PHP 示例 - 创建一次性支付订单

```php
<?php
// 准备请求数据
$data = [
    'customer_email' => 'customer@example.com',
    'items' => [
        [
            'name' => 'WordPress 高级主题',
            'price' => 29900, // 299.00 元
            'quantity' => 1,
            'payment_type' => 'onetime'
        ]
    ]
];

// 发送请求
$response = wp_remote_post(
    home_url('/wp-json/wpkj-fc-alipay/v1/custom-payment/create'),
    [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $your_auth_token
        ],
        'body' => json_encode($data)
    ]
);

if (!is_wp_error($response)) {
    $result = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($result['status'] === 'success') {
        $paymentUrl = $result['data']['payment_url'];
        // 重定向用户到支付页面
        wp_redirect($paymentUrl);
        exit;
    }
}
```

### PHP 示例 - 创建订阅支付订单

```php
<?php
$data = [
    'customer_email' => 'customer@example.com',
    'items' => [
        [
            'name' => 'WordPress 会员订阅',
            'price' => 9900, // 每月 99.00 元
            'quantity' => 1,
            'payment_type' => 'subscription',
            'subscription_info' => [
                'signup_fee' => 0, // 无注册费
                'times' => 0, // 无限次订阅
                'repeat_interval' => 'monthly' // 每月订阅
            ]
        ]
    ]
];

$response = wp_remote_post(
    home_url('/wp-json/wpkj-fc-alipay/v1/custom-payment/create'),
    [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $your_auth_token
        ],
        'body' => json_encode($data)
    ]
);
```

### JavaScript 示例

```javascript
// 创建支付订单
async function createPaymentOrder() {
  const data = {
    customer_email: 'customer@example.com',
    items: [
      {
        name: 'WordPress 插件',
        price: 19900, // 199.00 元
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
        'X-WP-Nonce': wpApiSettings.nonce
      },
      body: JSON.stringify(data)
    });

    const result = await response.json();
    
    if (result.status === 'success') {
      // 重定向到支付页面
      window.location.href = result.data.payment_url;
    }
  } catch (error) {
    console.error('创建订单失败:', error);
  }
}

// 查询支付状态
async function checkPaymentStatus(orderHash) {
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
      console.log('支付状态:', result.data.payment_status);
      console.log('是否已支付:', result.data.is_paid);
    }
  } catch (error) {
    console.error('查询状态失败:', error);
  }
}
```

## 自定义权限验证

你可以通过过滤器自定义 API 的权限验证逻辑：

```php
add_filter('wpkj_fc_alipay/custom_payment/check_permission', function($hasPermission, $request) {
    // 示例：允许特定 API Key 访问
    $apiKey = $request->get_header('X-API-Key');
    if ($apiKey === 'your-secret-api-key') {
        return true;
    }
    
    // 示例：允许特定 IP 地址访问
    $allowedIPs = ['192.168.1.100', '10.0.0.1'];
    $clientIP = $_SERVER['REMOTE_ADDR'];
    if (in_array($clientIP, $allowedIPs)) {
        return true;
    }
    
    return $hasPermission;
}, 10, 2);
```

## 订单创建钩子

Custom Payment Service 使用过滤器来创建 FluentCart 订单。你需要实现这个过滤器：

```php
add_filter('wpkj_fc_alipay/custom_payment/create_order', function($order, $orderData) {
    // 使用 FluentCart API 创建订单
    // 这里是示例实现，请根据实际情况调整
    
    $checkoutApi = new \FluentCart\Api\Checkout\CheckoutApi();
    
    // 准备订单数据
    $checkoutData = [
        'email' => $orderData['customer_email'],
        'items' => $orderData['items'],
        // 其他必要字段...
    ];
    
    // 创建订单
    $result = $checkoutApi->placeOrder($checkoutData);
    
    return $result['order'];
}, 10, 2);
```

## 支付流程

1. **创建订单**: 通过 API 创建支付订单
2. **获取支付链接**: API 返回支付链接
3. **跳转支付**: 引导用户到支付页面
4. **完成支付**: 用户通过支付宝完成支付
5. **支付回调**: 支付宝异步通知订单状态
6. **查询状态**: 通过 API 查询订单支付状态

## 注意事项

1. **价格单位**: 所有价格必须以"分"为单位，例如 100.00 元 = 10000 分
2. **权限安全**: 默认需要管理员权限，请根据需求自定义权限验证
3. **日志记录**: 所有操作都会记录日志，可在 FluentCart 日志中查看
4. **错误处理**: 请妥善处理 API 返回的错误信息
5. **HTTPS**: 生产环境建议使用 HTTPS 协议

## 支持的订阅间隔

- `daily` - 每天
- `weekly` - 每周
- `monthly` - 每月
- `quarterly` - 每季度
- `yearly` - 每年

## 故障排查

### 订单创建失败

1. 检查权限配置是否正确
2. 确认价格格式是否正确（必须是整数，单位为分）
3. 查看 FluentCart 日志获取详细错误信息

### 支付状态查询失败

1. 确认 order_hash 是否正确
2. 检查权限验证是否通过

## 更新日志

### Version 1.0.8
- 添加 Custom Payment API 支持
- 支持一次性支付和订阅支付
- 提供完整的 REST API 接口

## 技术支持

如有问题，请访问：https://www.wpdaxue.com
