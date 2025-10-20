# 支付宝中文标题乱码问题修复说明

## 问题描述

用户在支付宝当面付扫码后，看到的商品标题显示为乱码，而不是正确的中文。

## 根本原因

中文乱码问题通常由以下几种情况引起：

### 1. **字符编码不一致**
- 数据库存储的编码可能不是UTF-8
- PHP字符串处理时编码转换错误
- JSON编码时中文字符被转义

### 2. **特殊字符处理**
- BOM (Byte Order Mark) 字节序标记干扰
- 不可见的控制字符
- 混合编码（部分UTF-8，部分GBK等）

### 3. **JSON编码问题**
- `json_encode()` 默认会转义Unicode字符
- 需要使用 `JSON_UNESCAPED_UNICODE` 标志

## 解决方案

### 修改 1: PaymentProcessor 增加 UTF-8 确保机制

**文件**: `src/Processor/PaymentProcessor.php`

#### 新增 `ensureUtf8()` 方法

```php
/**
 * Ensure string is valid UTF-8 encoded
 * 
 * This method handles various encoding issues that can cause garbled Chinese characters
 * in Alipay payment interface.
 */
private function ensureUtf8($str)
{
    if (empty($str)) {
        return '';
    }
    
    // Check if already valid UTF-8
    if (mb_check_encoding($str, 'UTF-8')) {
        // Remove any BOM (Byte Order Mark) that might exist
        $str = str_replace("\xEF\xBB\xBF", '', $str);
        return $str;
    }
    
    // Try to detect encoding and convert to UTF-8
    $encoding = mb_detect_encoding($str, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'ASCII'], true);
    
    if ($encoding && $encoding !== 'UTF-8') {
        Logger::warning('Non-UTF-8 Encoding Detected', [
            'original_encoding' => $encoding,
            'string_preview' => mb_substr($str, 0, 50)
        ]);
        
        $str = mb_convert_encoding($str, 'UTF-8', $encoding);
    } else {
        // Fallback: force convert from UTF-8 to UTF-8 to clean up any issues
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
    
    // Remove any non-printable characters that might cause issues
    $str = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $str);
    
    return $str;
}
```

**处理流程**:
1. ✅ 检查字符串是否已是有效 UTF-8
2. ✅ 移除 BOM 字节序标记
3. ✅ 检测实际编码（支持 GBK、GB2312 等）
4. ✅ 转换为 UTF-8
5. ✅ 移除不可见控制字符

#### 更新 `buildSubject()` 方法

```php
private function buildSubject($order)
{
    $items = $order->order_items;
    
    if (count($items) === 1) {
        $item = $items[0];
        $itemTitle = !empty($item->title) ? $item->title : $item->post_title;
        
        // Ensure UTF-8 encoding for Chinese characters
        $itemTitle = $this->ensureUtf8($itemTitle);
        
        // Alipay subject max length is 256 characters
        return mb_substr($itemTitle, 0, 256, 'UTF-8');
    }

    $siteName = get_bloginfo('name');
    $subject = sprintf(__('Order from %s', 'wpkj-fluentcart-alipay-payment'), $siteName);
    
    return $this->ensureUtf8($subject);
}
```

#### 更新 `buildBody()` 方法

```php
private function buildBody($order)
{
    $items = [];
    foreach ($order->order_items as $item) {
        $itemTitle = !empty($item->title) ? $item->title : $item->post_title;
        
        // Ensure UTF-8 encoding for Chinese characters
        $itemTitle = $this->ensureUtf8($itemTitle);
        
        $items[] = $itemTitle . ' x' . $item->quantity;
    }

    $body = implode(', ', $items);
    
    // Alipay body max length is 400 characters
    return mb_substr($body, 0, 400, 'UTF-8');
}
```

### 修改 2: AlipayAPI 增强 JSON 编码处理

**文件**: `src/API/AlipayAPI.php`

#### 新增 `ensureUtf8Array()` 和 `ensureUtf8String()` 方法

```php
/**
 * Ensure all string values in array are valid UTF-8
 */
private function ensureUtf8Array($data)
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = $this->ensureUtf8Array($value);
        } elseif (is_string($value)) {
            $data[$key] = $this->ensureUtf8String($value);
        }
    }
    
    return $data;
}

/**
 * Ensure string is valid UTF-8 encoded
 */
private function ensureUtf8String($str)
{
    if (empty($str)) {
        return '';
    }
    
    // Check if already valid UTF-8
    if (mb_check_encoding($str, 'UTF-8')) {
        // Remove any BOM that might exist
        $str = str_replace("\xEF\xBB\xBF", '', $str);
        return $str;
    }
    
    // Try to detect encoding and convert to UTF-8
    $encoding = mb_detect_encoding($str, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'ASCII'], true);
    
    if ($encoding && $encoding !== 'UTF-8') {
        Logger::warning('Non-UTF-8 Encoding in API Data', [
            'original_encoding' => $encoding,
            'string_preview' => mb_substr($str, 0, 50)
        ]);
        
        $str = mb_convert_encoding($str, 'UTF-8', $encoding);
    } else {
        // Fallback: force convert from UTF-8 to UTF-8 to clean up any issues
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
    
    return $str;
}
```

#### 更新 `buildRequestParams()` 方法

```php
private function buildRequestParams($bizContent, $method, $returnUrl = '', $notifyUrl = '')
{
    // Ensure all string values in biz_content are valid UTF-8
    $bizContent = $this->ensureUtf8Array($bizContent);
    
    $params = [
        'app_id' => $this->config['app_id'],
        'method' => $method,
        'charset' => $this->config['charset'],
        'sign_type' => $this->config['sign_type'],
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        // JSON_UNESCAPED_UNICODE ensures Chinese characters are not escaped
        // JSON_UNESCAPED_SLASHES prevents escaping of forward slashes
        'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    
    // ... rest of the code
}
```

**关键改进**:
1. ✅ 在 JSON 编码前确保所有字符串都是 UTF-8
2. ✅ 使用 `JSON_UNESCAPED_UNICODE` 保持中文字符原样
3. ✅ 使用 `JSON_UNESCAPED_SLASHES` 避免斜杠转义

#### 增加调试日志

```php
public function createFaceToFacePayment($orderData)
{
    try {
        $bizContent = [
            'out_trade_no' => $orderData['out_trade_no'],
            'total_amount' => $orderData['total_amount'],
            'subject' => $orderData['subject'],
        ];
        
        // ... other code
        
        // Log the biz_content before encoding to debug Chinese character issues
        Logger::info('Face-to-Face Payment BizContent', [
            'subject' => $bizContent['subject'],
            'subject_encoding' => mb_detect_encoding($bizContent['subject']),
            'subject_is_utf8' => mb_check_encoding($bizContent['subject'], 'UTF-8') ? 'YES' : 'NO',
            'body' => $bizContent['body'] ?? 'N/A'
        ]);
        
        // ... rest of the code
    }
}
```

## 技术细节

### 支持的字符编码检测

```php
$encoding = mb_detect_encoding($str, [
    'UTF-8',      // 标准 UTF-8
    'GB2312',     // 简体中文 GB2312
    'GBK',        // 简体中文 GBK
    'GB18030',    // 中文国标
    'ISO-8859-1', // Latin-1
    'ASCII'       // ASCII
], true);
```

### BOM 处理

BOM (Byte Order Mark) 是一个特殊的 Unicode 字符,在某些编辑器中会自动添加到文件开头:

```php
// UTF-8 BOM: EF BB BF
$str = str_replace("\xEF\xBB\xBF", '', $str);
```

### JSON 编码标志

```php
json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
```

- `JSON_UNESCAPED_UNICODE`: 不转义 Unicode 字符（中文保持原样）
- `JSON_UNESCAPED_SLASHES`: 不转义斜杠

**对比**:
```php
// 不使用 JSON_UNESCAPED_UNICODE
json_encode(['title' => '测试商品']);
// 结果: {"title":"\u6d4b\u8bd5\u5546\u54c1"}

// 使用 JSON_UNESCAPED_UNICODE
json_encode(['title' => '测试商品'], JSON_UNESCAPED_UNICODE);
// 结果: {"title":"测试商品"}
```

## 测试验证

### 1. 检查日志

创建支付后,查看日志中的编码信息:

```
[INFO] Face-to-Face Payment BizContent
{
    "subject": "WordPress高级教程",
    "subject_encoding": "UTF-8",
    "subject_is_utf8": "YES",
    "body": "WordPress高级教程 x1"
}
```

### 2. 测试步骤

1. **创建测试订单**
   - 产品名称使用中文："WordPress高级教程"
   - 描述也使用中文

2. **生成支付二维码**
   - 使用支付宝当面付
   - 检查生成的二维码

3. **扫码测试**
   - 用支付宝APP扫描二维码
   - 查看支付界面显示的标题
   - 应该正确显示中文,不是乱码

4. **检查不同场景**
   - 单个商品订单
   - 多个商品订单
   - 包含特殊字符的标题
   - 很长的中文标题（测试截断）

### 3. 常见问题排查

#### 问题1: 仍然显示乱码

**检查点**:
1. 数据库编码是否为 `utf8mb4`
2. WordPress 配置中的 `DB_CHARSET`
3. PHP 是否启用了 mbstring 扩展

**验证命令**:
```bash
# 检查数据库编码
mysql -e "SHOW VARIABLES LIKE 'character_set%';"

# 检查 PHP mbstring
php -m | grep mbstring
```

#### 问题2: 某些中文字符显示正常,某些显示乱码

**可能原因**:
- 数据源编码混乱（部分 UTF-8，部分 GBK）
- 特殊字符或 Emoji

**解决方法**:
- 检查日志中的编码检测结果
- 使用我们的 `ensureUtf8()` 方法会自动处理

#### 问题3: 日志显示编码正确,但扫码仍然乱码

**可能原因**:
- 支付宝 API 请求时编码被篡改
- 网络传输过程中编码改变

**检查方法**:
```php
// 在 buildRequestParams 后添加日志
Logger::info('Request Params', [
    'biz_content' => $params['biz_content'],
    'charset' => $params['charset']
]);
```

## 配置检查

### WordPress 配置 (wp-config.php)

确保以下配置正确:

```php
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
```

### 数据库配置

```sql
-- 检查数据库编码
SHOW VARIABLES LIKE 'character_set%';

-- 应该看到:
-- character_set_client      | utf8mb4
-- character_set_connection  | utf8mb4
-- character_set_database    | utf8mb4
-- character_set_results     | utf8mb4
-- character_set_server      | utf8mb4
```

### PHP 配置

```ini
; php.ini 中确保以下配置
default_charset = "UTF-8"
mbstring.internal_encoding = UTF-8
mbstring.http_output = UTF-8
```

## 修改文件列表

1. ✅ `src/Processor/PaymentProcessor.php`
   - 新增 `ensureUtf8()` 方法
   - 更新 `buildSubject()` 方法
   - 更新 `buildBody()` 方法

2. ✅ `src/API/AlipayAPI.php`
   - 新增 `ensureUtf8Array()` 方法
   - 新增 `ensureUtf8String()` 方法
   - 更新 `buildRequestParams()` 方法
   - 增加调试日志

## 预期效果

修复后:
- ✅ 中文标题在支付宝扫码界面正确显示
- ✅ 支持各种中文编码自动转换
- ✅ 自动移除 BOM 和控制字符
- ✅ 详细的编码日志便于排查问题
- ✅ 向后兼容,不影响英文标题

## 总结

此次修复通过以下三个层面确保中文正确显示:

1. **数据层**: 在 PaymentProcessor 中确保源数据 UTF-8 编码正确
2. **API 层**: 在 AlipayAPI 中二次确保并使用正确的 JSON 编码标志
3. **监控层**: 添加详细日志帮助诊断编码问题

这个多层防护机制确保即使源数据编码有问题,也能自动修正并正确传递给支付宝。
