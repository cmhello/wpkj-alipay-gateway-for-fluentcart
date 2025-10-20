# 服务层使用指南

## 快速开始

本插件现已引入服务层架构,提供可复用的业务逻辑。所有开发者应使用这些服务类,而不是重复实现相同功能。

## OrderService - 订单服务

### 导入

```php
use WPKJFluentCart\Alipay\Services\OrderService;
```

### API 参考

#### clearCartOrderAssociation()

清除购物车与订单的关联,允许重复购买。

```php
/**
 * @param Order  $order   订单实例
 * @param string $context 调用上下文(用于日志追踪)
 * @return bool 成功返回 true
 */
OrderService::clearCartOrderAssociation(Order $order, string $context = 'payment_confirmation'): bool
```

**使用示例**:
```php
// 支付确认后
OrderService::clearCartOrderAssociation($order, 'payment_confirmation');

// 状态轮询
OrderService::clearCartOrderAssociation($order, 'status_polling');

// Webhook 通知
OrderService::clearCartOrderAssociation($order, 'webhook_notification');
```

#### getOrderByUuid()

通过 UUID 获取订单。

```php
OrderService::getOrderByUuid(string $uuid): ?Order

// 示例
$order = OrderService::getOrderByUuid('abc-123-def');
if ($order) {
    // 处理订单
}
```

#### isOrderCompleted()

检查订单是否已完成。

```php
OrderService::isOrderCompleted(Order $order): bool

// 示例
if (OrderService::isOrderCompleted($order)) {
    // 订单已完成,跳过处理
    return;
}
```

#### canOrderBePaid()

检查订单是否可支付。

```php
OrderService::canOrderBePaid(Order $order): array

// 返回
[
    'can_pay' => bool,
    'reason' => string
]

// 示例
$check = OrderService::canOrderBePaid($order);
if (!$check['can_pay']) {
    throw new Exception($check['reason']);
}
```

---

## EncodingService - 编码服务

### 导入

```php
use WPKJFluentCart\Alipay\Services\EncodingService;
```

### API 参考

#### ensureUtf8()

确保字符串是 UTF-8 编码。

```php
/**
 * @param string $str    输入字符串
 * @param bool   $strict 严格模式(失败时抛出异常)
 * @return string UTF-8 编码的字符串
 */
EncodingService::ensureUtf8(string $str, bool $strict = false): string
```

**使用示例**:
```php
// 基本使用
$clean = EncodingService::ensureUtf8($dirtyString);

// 严格模式
try {
    $clean = EncodingService::ensureUtf8($dirtyString, true);
} catch (Exception $e) {
    // 处理转换失败
}
```

#### ensureUtf8Array()

递归处理数组中的所有字符串。

```php
EncodingService::ensureUtf8Array(array $data, bool $strict = false): array

// 示例
$cleanData = EncodingService::ensureUtf8Array([
    'title' => '中文标题',
    'description' => 'GBK编码的文本',
    'items' => [
        ['name' => '商品A'],
        ['name' => '商品B']
    ]
]);
```

#### sanitizeForAlipay()

为支付宝 API 清理字符串(UTF-8 + 长度限制)。

```php
/**
 * @param string $str       输入字符串
 * @param int    $maxLength 最大长度(字符数)
 * @param bool   $strict    严格模式
 * @return string 清理后的字符串
 */
EncodingService::sanitizeForAlipay(
    string $str, 
    int $maxLength = 256, 
    bool $strict = false
): string
```

**使用示例**:
```php
// 支付标题(最大 256 字符)
$subject = EncodingService::sanitizeForAlipay($productTitle, 256);

// 支付描述(最大 400 字符)
$body = EncodingService::sanitizeForAlipay($productDescription, 400);
```

#### isValidUtf8()

验证字符串是否是有效的 UTF-8。

```php
EncodingService::isValidUtf8(string $str): bool

// 示例
if (!EncodingService::isValidUtf8($str)) {
    // 需要转换
    $str = EncodingService::ensureUtf8($str);
}
```

#### detectEncoding()

检测字符串编码。

```php
EncodingService::detectEncoding(string $str): string|false

// 示例
$encoding = EncodingService::detectEncoding($str);
// 返回: 'UTF-8', 'GBK', 'GB2312', etc.
```

#### getEncodingInfo()

获取详细的编码信息(用于调试)。

```php
EncodingService::getEncodingInfo(string $str): array

// 示例
$info = EncodingService::getEncodingInfo($problemString);
/*
返回:
[
    'detected_encoding' => 'GBK',
    'is_valid_utf8' => false,
    'length_bytes' => 20,
    'length_chars' => 10,
    'has_bom' => false,
    'preview' => '前100个字符...'
]
*/
```

---

## 支持的编码格式

EncodingService 支持自动检测和转换以下编码:

- **UTF-8** - 标准 UTF-8
- **GB2312** - 简体中文 GB2312
- **GBK** - 简体中文 GBK (扩展 GB2312)
- **GB18030** - 中国国家标准
- **BIG5** - 繁体中文
- **ISO-8859-1** - Latin-1
- **ASCII** - ASCII

---

## 最佳实践

### 1. 统一使用服务类

❌ **不要**在各处重复实现:
```php
// 错误示例
private function clearCart($order) {
    $cart = Cart::where('order_id', $order->id)->first();
    if ($cart) {
        $cart->order_id = null;
        $cart->save();
    }
}
```

✅ **使用**统一服务:
```php
// 正确示例
OrderService::clearCartOrderAssociation($order, 'my_context');
```

### 2. 提供有意义的上下文

❌ **不好**:
```php
OrderService::clearCartOrderAssociation($order);
```

✅ **推荐**:
```php
OrderService::clearCartOrderAssociation($order, 'webhook_notification');
OrderService::clearCartOrderAssociation($order, 'manual_refund');
OrderService::clearCartOrderAssociation($order, 'status_polling');
```

### 3. 处理中文时使用专用方法

❌ **不要**手动处理:
```php
$title = mb_convert_encoding($title, 'UTF-8', 'GBK');
$title = mb_substr($title, 0, 256, 'UTF-8');
```

✅ **使用**专用方法:
```php
$title = EncodingService::sanitizeForAlipay($title, 256);
```

### 4. 检查返回值(可选)

虽然服务方法会记录错误,但你也可以检查返回值:

```php
if (!OrderService::clearCartOrderAssociation($order, $context)) {
    Logger::warning('Failed to clear cart association', [
        'order_id' => $order->id,
        'context' => $context
    ]);
    // 注意: 这不应影响支付成功的流程
}
```

---

## 调试技巧

### 1. 检查编码问题

```php
// 获取详细编码信息
$info = EncodingService::getEncodingInfo($suspiciousString);
Logger::debug('Encoding Debug', $info);

// 输出:
// detected_encoding: GBK
// is_valid_utf8: false
// has_bom: true
```

### 2. 追踪购物车清除

所有 `clearCartOrderAssociation` 调用都会产生日志:

```
[INFO] Cart Order Association Cleared
{
    "cart_id": 123,
    "order_id": 5,
    "context": "payment_confirmation",  // 可追踪调用来源
    "changes": {
        "order_id": {"from": 5, "to": null}
    }
}
```

---

## 扩展服务

如果需要添加新的服务方法,请遵循以下规范:

### 1. 方法签名

```php
/**
 * 清晰的方法说明
 * 
 * @param Type $param 参数说明
 * @return Type 返回值说明
 */
public static function methodName(Type $param): Type
```

### 2. 错误处理

```php
try {
    // 业务逻辑
} catch (\Exception $e) {
    Logger::error('Operation Failed', [
        'error' => $e->getMessage(),
        'context' => $context
    ]);
    return false; // 或抛出异常,视情况而定
}
```

### 3. 日志记录

```php
Logger::info('Operation Success', [
    'relevant_data' => $data,
    'context' => $context
]);
```

---

## 性能考虑

- **静态方法**: 无实例化开销
- **缓存**: 编码检测结果可能会被 PHP 内部缓存
- **批量处理**: 使用 `ensureUtf8Array()` 比循环调用 `ensureUtf8()` 更清晰

---

## 版本历史

- **v1.0.6** (2025-10-20) - 初次引入服务层
  - OrderService
  - EncodingService

---

## 需要帮助?

如有疑问,请查看:
- [REFACTORING_REPORT.md](REFACTORING_REPORT.md) - 详细重构报告
- [代码示例](src/Processor/PaymentProcessor.php) - 实际使用示例
