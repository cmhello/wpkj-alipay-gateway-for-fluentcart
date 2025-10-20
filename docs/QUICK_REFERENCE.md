# 支付宝插件开发快速参考

## 常用模块速查

### 客户端检测

```php
use WPKJFluentCart\Alipay\Detector\ClientDetector;

// 获取客户端类型 (推荐)
$clientType = ClientDetector::detect(); // 'alipay'|'mobile'|'pc'

// 检测是否为支付宝客户端
if (ClientDetector::isAlipayClient()) {
    // 支付宝 APP 内
}

// 检测是否为移动设备
if (ClientDetector::isMobile()) {
    // 移动设备
}

// 获取适配的支付方法
$method = ClientDetector::getPaymentMethod($settings);
// 'alipay.trade.app.pay' | 'alipay.trade.wap.pay' | 
// 'alipay.trade.precreate' | 'alipay.trade.page.pay'
```

### 日志记录

```php
use WPKJFluentCart\Alipay\Utils\Logger;

// 信息日志
Logger::info('Title', 'Content', ['key' => 'value']);

// 警告日志
Logger::warning('Title', 'Content');

// 错误日志
Logger::error('Title', ['error' => $e->getMessage()]);
```

### 金额处理

```php
use WPKJFluentCart\Alipay\Utils\Helper;

// 分转元 (cents to yuan)
$yuan = Helper::toDecimal(10000); // "100.00"

// 元转分 (yuan to cents)
$cents = Helper::toCents("100.00"); // 10000

// 生成订单号
$outTradeNo = Helper::generateOutTradeNo($uuid);
```

### 配置常量

```php
use WPKJFluentCart\Alipay\Config\AlipayConfig;

// 最低支付金额 (分)
AlipayConfig::MIN_PAYMENT_AMOUNT_CENTS // 1

// 最大单笔交易金额 (元)
AlipayConfig::MAX_SINGLE_TRANSACTION_AMOUNT // 50000000

// 标题最大长度
AlipayConfig::MAX_SUBJECT_LENGTH // 256

// 描述最大长度
AlipayConfig::MAX_BODY_LENGTH // 128

// 支付超时时间
AlipayConfig::PAYMENT_TIMEOUT_MINUTES // 30
AlipayConfig::DEFAULT_PAYMENT_TIMEOUT // "30m"
```

### 编码处理

```php
use WPKJFluentCart\Alipay\Services\EncodingService;

// 清理文本用于支付宝
$clean = EncodingService::sanitizeForAlipay($text, $maxLength);

// 确保 UTF-8 编码
$utf8Text = EncodingService::ensureUtf8($text);
```

## 支付流程

### 单次支付

```php
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;

$processor = new PaymentProcessor($settings);
$result = $processor->processSinglePayment($paymentInstance);

// 返回格式:
[
    'status' => 'success',
    'nextAction' => 'redirect',
    'redirect_to' => 'https://...'
]
```

### 订阅支付

```php
use WPKJFluentCart\Alipay\Subscription\AlipaySubscriptionProcessor;

$processor = new AlipaySubscriptionProcessor($settings);
$result = $processor->processSubscription($paymentInstance);
```

### 退款处理

```php
use WPKJFluentCart\Alipay\Processor\RefundProcessor;

$processor = new RefundProcessor($settings);
$result = $processor->processRefund($transaction, $refundAmount, $reason);
```

## API 调用

### 创建支付

```php
use WPKJFluentCart\Alipay\API\AlipayAPI;

$api = new AlipayAPI($settings);

// 网页支付
$result = $api->createPagePayment($params);

// 手机网站支付
$result = $api->createWapPayment($params);

// 扫码支付
$result = $api->createFaceToFacePayment($params);
```

### 查询订单

```php
$result = $api->queryOrder($outTradeNo);

if (!is_wp_error($result)) {
    $tradeStatus = $result['trade_status'];
}
```

### 退款

```php
$result = $api->refund([
    'out_trade_no' => $outTradeNo,
    'refund_amount' => Helper::toDecimal($refundAmount),
    'refund_reason' => $reason
]);
```

## 订阅功能

### 周期扣款协议

```php
use WPKJFluentCart\Alipay\Subscription\AlipayRecurringAgreement;

$recurring = new AlipayRecurringAgreement($settings);

// 检查是否启用
if ($recurring->isRecurringEnabled()) {
    // 创建签约
    $result = $recurring->createAgreementSign($subscription, $orderData);
    
    // 执行代扣
    $result = $recurring->executeAgreementPay($subscription, $amount, $orderData);
    
    // 查询协议
    $result = $recurring->queryAgreement($agreementNo);
    
    // 解约
    $result = $recurring->unsignAgreement($subscription);
}
```

## 常见场景代码示例

### 场景 1: 根据客户端类型处理支付

```php
use WPKJFluentCart\Alipay\Detector\ClientDetector;

$clientType = ClientDetector::detect();

switch ($clientType) {
    case 'alipay':
        // 支付宝 APP 内,使用 APP 支付
        $paymentMethod = 'alipay.trade.app.pay';
        break;
        
    case 'mobile':
        // 移动浏览器,使用 WAP 支付
        $paymentMethod = 'alipay.trade.wap.pay';
        break;
        
    case 'pc':
        // PC 端,使用网页支付或扫码支付
        $enableF2F = $this->settings->get('enable_face_to_face_pc');
        $paymentMethod = ($enableF2F === 'yes') 
            ? 'alipay.trade.precreate' 
            : 'alipay.trade.page.pay';
        break;
}
```

### 场景 2: 处理订阅续费

```php
// 检查是否有活跃协议
if ($subscription->vendor_subscription_id) {
    $agreementStatus = $subscription->getMeta('alipay_agreement_status');
    
    if ($agreementStatus === 'active') {
        // 使用协议代扣
        $result = $recurring->executeAgreementPay(
            $subscription, 
            $renewalAmount, 
            $orderData
        );
        
        if (!is_wp_error($result)) {
            // 代扣成功
            return ['status' => 'success'];
        }
    }
}

// 降级到手动支付
return $this->processManualPayment($subscription, $paymentInstance);
```

### 场景 3: 确认支付成功

```php
use WPKJFluentCart\Alipay\Processor\PaymentProcessor;
use FluentCart\App\Helpers\Status;

$processor = new PaymentProcessor($settings);

// 在异步通知中确认支付
if ($alipayData['trade_status'] === 'TRADE_SUCCESS') {
    $processor->confirmPaymentSuccess($transaction, $alipayData);
    
    // 检查交易状态
    if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
        // 支付已确认
    }
}
```

### 场景 4: 构建支付参数

```php
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Services\EncodingService;
use WPKJFluentCart\Alipay\Config\AlipayConfig;

// 生成唯一订单号
$outTradeNo = Helper::generateOutTradeNo($transaction->uuid);

// 金额转换
$totalAmount = Helper::toDecimal($transaction->total);

// 标题和描述清理
$subject = EncodingService::sanitizeForAlipay($rawSubject, AlipayConfig::MAX_SUBJECT_LENGTH);
$body = EncodingService::sanitizeForAlipay($rawBody, AlipayConfig::MAX_BODY_LENGTH);

// 构建参数
$params = [
    'out_trade_no' => $outTradeNo,
    'total_amount' => $totalAmount,
    'subject' => $subject,
    'body' => $body,
    'return_url' => $returnUrl,
    'notify_url' => $notifyUrl,
    'timeout_express' => AlipayConfig::DEFAULT_PAYMENT_TIMEOUT
];
```

### 场景 5: 错误处理

```php
use WPKJFluentCart\Alipay\Utils\Logger;

try {
    $result = $api->createPayment($params);
    
    if (is_wp_error($result)) {
        throw new \Exception($result->get_error_message());
    }
    
    Logger::info('Payment Created', [
        'out_trade_no' => $params['out_trade_no'],
        'amount' => $params['total_amount']
    ]);
    
    return $result;
    
} catch (\Exception $e) {
    Logger::error('Payment Failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    return [
        'status' => 'failed',
        'message' => $e->getMessage()
    ];
}
```

## 数据结构

### PaymentInstance

```php
$paymentInstance = {
    order: Order,                // 订单对象
    transaction: OrderTransaction, // 交易对象
    subscription: Subscription,   // 订阅对象 (如果是订阅支付)
    customer: Customer            // 客户对象
}
```

### 订阅元数据

```php
// 协议信息
$subscription->vendor_subscription_id; // 协议号
$subscription->getMeta('alipay_agreement_status'); // 'active'|'inactive'
$subscription->getMeta('external_agreement_no'); // 外部协议号

// 周期信息
$subscription->billing_interval; // 'day'|'week'|'month'|'year'
$subscription->billing_interval_count; // 计费间隔次数
$subscription->next_billing_date; // 下次计费日期
```

### 交易元数据

```php
// 支付宝信息
$transaction->vendor_charge_id; // 支付宝交易号
$transaction->getMeta('out_trade_no'); // 商户订单号
$transaction->getMeta('alipay_trade_no'); // 支付宝交易号

// 买家信息
$transaction->getMeta('buyer_logon_id'); // 买家支付宝账号
$transaction->getMeta('buyer_user_id'); // 买家用户 ID
```

## 回调处理

### 异步通知

```php
use WPKJFluentCart\Alipay\Webhook\NotifyHandler;

// 在 NotifyHandler::processNotify() 中:
public function processNotify()
{
    $data = $_POST;
    
    // 验签
    if (!$this->api->verifyNotify($data)) {
        echo 'failure';
        return;
    }
    
    // 处理支付成功
    if ($data['trade_status'] === 'TRADE_SUCCESS') {
        $this->handlePaymentSuccess($data);
    }
    
    // 处理协议签约
    if (isset($_GET['action']) && $_GET['action'] === 'agreement') {
        $this->processAgreementNotify($data);
    }
    
    echo 'success';
}
```

### 同步返回

```php
use WPKJFluentCart\Alipay\Webhook\ReturnHandler;

// 在 ReturnHandler::handleReturn() 中:
public function handleReturn()
{
    $data = $_GET;
    
    // 验签
    if (!$this->api->verifyReturn($data)) {
        // 验签失败
        return;
    }
    
    // 跳转到订单确认页
    wp_redirect($receiptUrl);
    exit;
}
```

## 调试技巧

### 查看日志

```php
// 日志位置
wp-content/uploads/fluent-cart-logs/alipay-{date}.log

// 日志级别
- info: 一般信息
- warning: 警告信息
- error: 错误信息
```

### 测试环境

```php
// 沙箱环境配置
$settings->update('sandbox_mode', 'yes');
$settings->update('sandbox_app_id', '...');
$settings->update('sandbox_private_key', '...');
$settings->update('sandbox_public_key', '...');
```

### 常见问题

**问题 1**: `out_trade_no` 重复  
**解决**: 使用 `Helper::generateOutTradeNo()` 生成带时间戳的订单号

**问题 2**: 金额格式错误  
**解决**: 使用 `Helper::toDecimal()` 转换金额格式

**问题 3**: 中文乱码  
**解决**: 使用 `EncodingService::sanitizeForAlipay()` 清理文本

**问题 4**: 客户端类型判断错误  
**解决**: 统一使用 `ClientDetector::detect()`

## 最佳实践

### ✅ 推荐做法

```php
// 1. 使用统一的客户端检测
$clientType = ClientDetector::detect();

// 2. 使用统一的日志记录
Logger::info('Title', 'Content', $context);

// 3. 使用统一的金额处理
$amount = Helper::toDecimal($cents);

// 4. 使用常量而非硬编码
AlipayConfig::MAX_SUBJECT_LENGTH

// 5. 错误处理使用 WP_Error
if (is_wp_error($result)) {
    return $result;
}
```

### ❌ 避免做法

```php
// 1. 不要重复实现检测逻辑
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile')) { ... } // ❌

// 2. 不要直接使用 error_log
error_log('Payment failed'); // ❌

// 3. 不要硬编码金额计算
$yuan = $cents / 100; // ❌

// 4. 不要硬编码配置值
if (strlen($subject) > 256) { ... } // ❌

// 5. 不要忽略错误检查
$result = $api->createPayment($params);
return $result['payment_url']; // ❌ 没有检查错误
```

---

**快速参考版本**: 1.0.0  
**更新日期**: 2025-10-20
