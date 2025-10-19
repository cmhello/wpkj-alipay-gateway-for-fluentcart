# WPKJ FluentCart Alipay Payment - 当面付功能

## 快速启用

### 1. 配置当面付
```
WordPress 后台 → FluentCart → 设置 → 支付方式 → Alipay
→ 勾选 "Enable Face-to-Face payment for PC/Desktop"
→ 保存
```

### 2. 支付流程
- **PC端（启用当面付）**：显示二维码 → 扫码支付 → 自动跳转
- **PC端（未启用）**：页面跳转支付（传统方式）
- **移动端**：WAP支付（不受影响）
- **支付宝APP内**：APP支付（不受影响）

## 功能特性

✅ 自动检测设备类型  
✅ 二维码自动生成和显示  
✅ 支付状态实时轮询（3秒/次）  
✅ 支付成功自动跳转  
✅ 超时提示（10分钟）  
✅ 完整的国际化支持  
✅ 响应式设计  
✅ 与退款功能兼容  

## 技术实现

### 前端
- **face-to-face-payment.js** - 支付流程处理
- **face-to-face-payment.css** - UI样式
- 支持 QRCode.js 或 Google Charts API

### 后端
- **PaymentStatusChecker.php** - 支付状态查询
- **AlipayAPI::createFaceToFacePayment()** - 调用支付宝API
- **ClientDetector** - 智能设备检测

### 国际化
所有用户可见文本已支持翻译：
- 扫码支付标题
- 等待支付提示
- 成功/失败/超时消息

## 常见问题

**Q: 移动端会显示二维码吗？**  
A: 不会，系统会自动检测设备类型，移动端继续使用WAP支付。

**Q: 需要额外配置吗？**  
A: 不需要，只需在设置中启用即可，使用相同的支付宝密钥。

**Q: 如何关闭当面付？**  
A: 在设置中取消勾选"Enable Face-to-Face payment for PC/Desktop"。

## 版本要求

- WordPress 6.5+
- PHP 8.2+
- FluentCart 1.2.0+
- 支付宝开放平台当面付权限

---

**版本**: 1.0.3  
**更新**: 2025-01-19
