# 支付宝签名验证问题修复说明

## 问题描述

在测试支付时，支付宝返回错误：
```
invalid-signature 验签出错，建议检查签名字符串或签名私钥与应用公钥是否匹配
```

错误信息显示支付宝收到的验签字符串中包含 HTML 编码字符：
- `&amp;` (应该是 `&`)
- `&quot;` (应该是 `"`)

## 根本原因

WordPress 的 `add_query_arg()` 函数会对 URL 参数进行 HTML 编码，导致：

1. **notify_url** 包含编码字符：`?fct_payment_listener=1&amp;method=alipay`
2. **return_url** 包含编码字符：`?method=alipay&amp;trx_hash=xxx`

这些编码后的 URL 作为参数传递给支付宝，支付宝在进行签名验证时会使用这些编码后的字符串，导致签名不匹配。

## 解决方案

### 修改文件
`src/Processor/PaymentProcessor.php`

### 修改内容

1. **新增 `getReturnUrl()` 方法**
   - 使用 `http_build_query()` 配合 `PHP_QUERY_RFC3986` 参数
   - 避免 HTML 编码，生成标准 URL

2. **修改 `getNotifyUrl()` 方法**
   - 同样使用 `http_build_query()` 替代 `add_query_arg()`
   - 确保 URL 中只包含标准字符

3. **修改 `buildPaymentData()` 方法**
   - 使用新的 URL 生成方法
   - 移除对 FluentCart `PaymentHelper::successUrl()` 的依赖

## 技术细节

### 错误的方式（会被 HTML 编码）
```php
$url = add_query_arg([
    'fct_payment_listener' => '1',
    'method' => 'alipay'
], site_url('/'));
// 结果: https://example.com/?fct_payment_listener=1&amp;method=alipay
```

### 正确的方式（不会被编码）
```php
$params = http_build_query([
    'fct_payment_listener' => '1',
    'method' => 'alipay'
], '', '&', PHP_QUERY_RFC3986);
$url = site_url('/') . '?' . $params;
// 结果: https://example.com/?fct_payment_listener=1&method=alipay
```

## 验证修复

修复后生成的 URL：
- **Notify URL**: `https://waas.wpdaxue.com/?fct_payment_listener=1&method=alipay`
- **Return URL**: `https://waas.wpdaxue.com/receipt/?method=alipay&trx_hash=xxx&fct_redirect=yes`

✅ 不包含 `&amp;` 或 `&quot;` 等 HTML 编码字符
✅ 支付宝可以正确进行签名验证

## 其他修复

1. **AlipaySettingsBase.php**
   - 修复 Helper 类引用（使用 `FluentCartHelper` 进行解密）

2. **AlipayAPI.php**
   - 增加签名生成的错误处理
   - 添加调试日志（仅在测试模式）

## 配置检查清单

请确保以下配置正确：

- [ ] 在支付宝沙箱选择"非 Java 语言"的密钥格式
- [ ] 上传的应用公钥与插件中的应用私钥是同一密钥对
- [ ] 支付宝公钥已从沙箱获取并正确配置
- [ ] App ID、私钥、公钥都已正确填写

## 工具

### 生成配对的应用公钥
```bash
php generate-public-key.php
```

这个工具会从您配置的应用私钥中提取对应的公钥，上传到支付宝沙箱。

## 日期
2025-10-19
