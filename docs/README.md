# 支付宝插件文档中心

欢迎使用 WP考界 FluentCart 支付宝支付插件!本文档中心提供了完整的开发指南和技术文档。

## 📚 文档导航

### 入门指南

- **[订阅功能支持说明](../SUBSCRIPTION_SUPPORT.md)**  
  了解插件的订阅功能特性、支持的订阅模式、使用方法等

### 配置指南

- **[周期扣款配置指南](../RECURRING_PAYMENT_GUIDE.md)**  
  详细的支付宝周期扣款(自动续费)功能配置教程，包括:
  - 商家签约流程
  - 产品码配置
  - 双模式订阅系统
  - 测试方法

### 开发文档

- **[模块化开发规范](./MODULE_STANDARDIZATION.md)** ⭐ 推荐  
  插件的模块化架构设计和开发规范，包括:
  - 命名空间规范
  - 模块职责划分
  - 代码复用原则
  - 客户端检测模块详解

- **[快速参考手册](./QUICK_REFERENCE.md)** ⭐ 推荐  
  日常开发的速查手册，包括:
  - 常用模块 API
  - 代码示例
  - 最佳实践
  - 调试技巧

- **[订阅功能技术实现总结](./SUBSCRIPTION_IMPLEMENTATION_SUMMARY.md)**  
  订阅功能的技术实现细节，包括:
  - 架构设计
  - 核心类说明
  - 支付流程
  - 代码示例

### API 参考

- **客户端检测 API**  
  位置: `WPKJFluentCart\Alipay\Detector\ClientDetector`
  ```php
  ClientDetector::detect()           // 获取客户端类型
  ClientDetector::isAlipayClient()   // 检测支付宝客户端
  ClientDetector::isMobile()         // 检测移动设备
  ClientDetector::getPaymentMethod() // 获取支付方法
  ```

- **支付处理 API**  
  位置: `WPKJFluentCart\Alipay\Processor\PaymentProcessor`
  ```php
  $processor->processSinglePayment($paymentInstance)
  $processor->confirmPaymentSuccess($transaction, $data)
  $processor->processFailedPayment($transaction, $data)
  ```

- **订阅处理 API**  
  位置: `WPKJFluentCart\Alipay\Subscription\AlipaySubscriptionProcessor`
  ```php
  $processor->processSubscription($paymentInstance)
  ```

- **周期扣款 API**  
  位置: `WPKJFluentCart\Alipay\Subscription\AlipayRecurringAgreement`
  ```php
  $recurring->isRecurringEnabled()
  $recurring->createAgreementSign($subscription, $orderData)
  $recurring->executeAgreementPay($subscription, $amount, $orderData)
  $recurring->queryAgreement($agreementNo)
  $recurring->unsignAgreement($subscription)
  ```

## 🏗️ 项目结构

```
wpkj-fluentcart-alipay-payment/
├── src/                              # 源代码目录
│   ├── API/                          # 支付宝 API 封装
│   │   └── AlipayAPI.php
│   ├── Config/                       # 配置管理
│   │   └── AlipayConfig.php
│   ├── Detector/                     # 客户端检测 ⭐
│   │   └── ClientDetector.php
│   ├── Gateway/                      # 支付网关
│   │   ├── AlipayGateway.php
│   │   └── AlipaySettingsBase.php
│   ├── Processor/                    # 支付处理器
│   │   ├── PaymentProcessor.php
│   │   ├── RefundProcessor.php
│   │   ├── QueryProcessor.php
│   │   └── TransactionProcessor.php
│   ├── Subscription/                 # 订阅功能 ⭐
│   │   ├── AlipaySubscriptions.php
│   │   ├── AlipaySubscriptionProcessor.php
│   │   └── AlipayRecurringAgreement.php
│   ├── Services/                     # 业务服务
│   │   ├── EncodingService.php
│   │   └── OrderService.php
│   ├── Utils/                        # 工具类
│   │   ├── Logger.php
│   │   └── Helper.php
│   └── Webhook/                      # 回调处理
│       ├── NotifyHandler.php
│       └── ReturnHandler.php
├── docs/                             # 文档目录 📚
│   ├── README.md                     # 本文件
│   ├── MODULE_STANDARDIZATION.md     # 模块化规范
│   ├── QUICK_REFERENCE.md            # 快速参考
│   └── SUBSCRIPTION_IMPLEMENTATION_SUMMARY.md
├── SUBSCRIPTION_SUPPORT.md           # 订阅功能说明
└── RECURRING_PAYMENT_GUIDE.md        # 周期扣款指南
```

## 🎯 快速开始

### 1. 开发前必读

阅读以下文档了解插件架构:

1. [模块化开发规范](./MODULE_STANDARDIZATION.md) - 了解代码组织方式
2. [快速参考手册](./QUICK_REFERENCE.md) - 查看常用 API 和示例

### 2. 添加新功能

```php
// 1. 确定功能所属模块
// 2. 查看快速参考手册找到相关 API
// 3. 遵循模块化规范编写代码

// 示例: 添加支付处理逻辑
use WPKJFluentCart\Alipay\Detector\ClientDetector;
use WPKJFluentCart\Alipay\Utils\Logger;
use WPKJFluentCart\Alipay\Config\AlipayConfig;

// 使用统一的客户端检测
$clientType = ClientDetector::detect();

// 使用统一的日志记录
Logger::info('Payment Started', ['type' => $clientType]);

// 使用配置常量
if ($amount > AlipayConfig::MAX_SINGLE_TRANSACTION_AMOUNT) {
    // 处理超限
}
```

### 3. 调试问题

1. 查看日志: `wp-content/uploads/fluent-cart-logs/alipay-{date}.log`
2. 参考 [快速参考手册 - 调试技巧](./QUICK_REFERENCE.md#调试技巧)
3. 检查 [常见问题](./QUICK_REFERENCE.md#常见问题)

## 📖 核心概念

### 客户端检测

插件使用统一的 `ClientDetector` 模块检测用户环境:

```php
use WPKJFluentCart\Alipay\Detector\ClientDetector;

$type = ClientDetector::detect(); // 'alipay' | 'mobile' | 'pc'
```

详细说明: [模块化开发规范 - 客户端检测模块](./MODULE_STANDARDIZATION.md#客户端检测模块-clientdetector)

### 双模式订阅

插件支持两种订阅模式:

1. **自动续费模式**: 使用支付宝周期扣款协议
2. **手动续费模式**: 每次续费需要用户手动支付

详细说明: [订阅功能支持说明](../SUBSCRIPTION_SUPPORT.md)

### 智能降级策略

当自动续费失败时,系统自动降级到手动续费:

```
初始订阅
├─ 周期扣款已启用?
│  ├─ YES → 创建签约
│  │  ├─ 成功 → 签约页面
│  │  └─ 失败 → 降级到手动支付 ⬇️
│  └─ NO → 手动支付

续费支付
├─ 有活跃协议?
│  ├─ YES → 协议代扣
│  │  ├─ 成功 → 自动完成
│  │  └─ 失败 → 降级到手动支付 ⬇️
│  └─ NO → 手动支付
```

详细说明: [周期扣款配置指南](../RECURRING_PAYMENT_GUIDE.md)

## 🔧 开发规范

### 命名空间规范

所有代码必须在 `WPKJFluentCart\Alipay` 命名空间下:

```php
namespace WPKJFluentCart\Alipay\{模块名};

// 示例
namespace WPKJFluentCart\Alipay\Detector;
namespace WPKJFluentCart\Alipay\Processor;
namespace WPKJFluentCart\Alipay\Subscription;
```

### 模块职责

| 模块 | 职责 | 何时使用 |
|------|------|----------|
| `Detector\` | 客户端环境检测 | 需要判断用户设备类型时 |
| `Gateway\` | 支付网关集成 | 网关配置和初始化 |
| `Processor\` | 支付处理逻辑 | 处理支付、退款等操作 |
| `Subscription\` | 订阅功能 | 处理订阅相关逻辑 |
| `API\` | 支付宝 API | 调用支付宝接口 |
| `Config\` | 配置管理 | 获取配置值和常量 |
| `Utils\` | 工具类 | 日志、金额处理等工具 |
| `Services\` | 业务服务 | 编码、订单等业务逻辑 |
| `Webhook\` | 回调处理 | 处理支付宝通知 |

详细说明: [模块化开发规范](./MODULE_STANDARDIZATION.md)

### 代码风格

```php
// ✅ 推荐: 使用统一的模块
use WPKJFluentCart\Alipay\Detector\ClientDetector;
$type = ClientDetector::detect();

// ❌ 避免: 重复实现
if (wp_is_mobile()) { ... }

// ✅ 推荐: 使用常量
if ($length > AlipayConfig::MAX_SUBJECT_LENGTH) { ... }

// ❌ 避免: 硬编码
if ($length > 256) { ... }

// ✅ 推荐: 统一日志
Logger::info('Title', 'Content');

// ❌ 避免: 直接输出
error_log('...');
```

详细说明: [快速参考手册 - 最佳实践](./QUICK_REFERENCE.md#最佳实践)

## 📝 文档更新记录

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.0.0 | 2025-10-20 | 初始版本,包含完整的模块化文档 |

## 🤝 贡献指南

### 提交代码前检查

- [ ] 代码遵循命名空间规范
- [ ] 使用现有模块而非重复实现
- [ ] 添加了 PHPDoc 注释
- [ ] 添加了日志记录
- [ ] 进行了错误处理
- [ ] 更新了相关文档

### 文档贡献

欢迎完善文档!提交前请确保:

- 使用清晰的 Markdown 格式
- 代码示例可直接运行
- 链接引用正确
- 保持文档结构一致

## 📞 联系我们

- **开发者**: WPKJ Development Team
- **项目主页**: https://wpdaxue.com
- **技术支持**: 通过工单系统

## 📄 许可证

本插件遵循 GPL v2 或更高版本许可证。

---

**文档中心版本**: 1.0.0  
**最后更新**: 2025-10-20  
**维护者**: WPKJ Development Team

- **客户端检测 API**  
  位置: `WPKJFluentCart\Alipay\Detector\ClientDetector`
  ```php
  ClientDetector::detect()           // 获取客户端类型
  ClientDetector::isAlipayClient()   // 检测支付宝客户端
  ClientDetector::isMobile()         // 检测移动设备
  ClientDetector::getPaymentMethod() // 获取支付方法
  ```

- **支付处理 API**  
  位置: `WPKJFluentCart\Alipay\Processor\PaymentProcessor`
  ```php
  $processor->processSinglePayment($paymentInstance)
  $processor->confirmPaymentSuccess($transaction, $data)
  $processor->processFailedPayment($transaction, $data)
  ```

- **订阅处理 API**  
  位置: `WPKJFluentCart\Alipay\Subscription\AlipaySubscriptionProcessor`
  ```php
  $processor->processSubscription($paymentInstance)
  ```

- **周期扣款 API**  
  位置: `WPKJFluentCart\Alipay\Subscription\AlipayRecurringAgreement`
  ```php
  $recurring->isRecurringEnabled()
  $recurring->createAgreementSign($subscription, $orderData)
  $recurring->executeAgreementPay($subscription, $amount, $orderData)
  $recurring->queryAgreement($agreementNo)
  $recurring->unsignAgreement($subscription)
  ```

## 🏗️ 项目结构

```
wpkj-fluentcart-alipay-payment/
├── src/                              # 源代码目录
│   ├── API/                          # 支付宝 API 封装
│   │   └── AlipayAPI.php
│   ├── Config/                       # 配置管理
│   │   └── AlipayConfig.php
│   ├── Detector/                     # 客户端检测 ⭐
│   │   └── ClientDetector.php
│   ├── Gateway/                      # 支付网关
│   │   ├── AlipayGateway.php
│   │   └── AlipaySettingsBase.php
│   ├── Processor/                    # 支付处理器
│   │   ├── PaymentProcessor.php
│   │   ├── RefundProcessor.php
│   │   ├── QueryProcessor.php
│   │   └── TransactionProcessor.php
│   ├── Subscription/                 # 订阅功能 ⭐
│   │   ├── AlipaySubscriptions.php
│   │   ├── AlipaySubscriptionProcessor.php
│   │   └── AlipayRecurringAgreement.php
│   ├── Services/                     # 业务服务
│   │   ├── EncodingService.php
│   │   └── OrderService.php
│   ├── Utils/                        # 工具类
│   │   ├── Logger.php
│   │   └── Helper.php
│   └── Webhook/                      # 回调处理
│       ├── NotifyHandler.php
│       └── ReturnHandler.php
├── docs/                             # 文档目录 📚
│   ├── README.md                     # 本文件
│   ├── MODULE_STANDARDIZATION.md     # 模块化规范
│   ├── QUICK_REFERENCE.md            # 快速参考
│   └── SUBSCRIPTION_IMPLEMENTATION_SUMMARY.md
├── SUBSCRIPTION_SUPPORT.md           # 订阅功能说明
└── RECURRING_PAYMENT_GUIDE.md        # 周期扣款指南
```

## 🎯 快速开始

### 1. 开发前必读

阅读以下文档了解插件架构:

1. [模块化开发规范](./MODULE_STANDARDIZATION.md) - 了解代码组织方式
2. [快速参考手册](./QUICK_REFERENCE.md) - 查看常用 API 和示例

### 2. 添加新功能

```php
// 1. 确定功能所属模块
// 2. 查看快速参考手册找到相关 API
// 3. 遵循模块化规范编写代码

// 示例: 添加支付处理逻辑
use WPKJFluentCart\Alipay\Detector\ClientDetector;
use WPKJFluentCart\Alipay\Utils\Logger;
use WPKJFluentCart\Alipay\Config\AlipayConfig;

// 使用统一的客户端检测
$clientType = ClientDetector::detect();

// 使用统一的日志记录
Logger::info('Payment Started', ['type' => $clientType]);

// 使用配置常量
if ($amount > AlipayConfig::MAX_SINGLE_TRANSACTION_AMOUNT) {
    // 处理超限
}
```

### 3. 调试问题

1. 查看日志: `wp-content/uploads/fluent-cart-logs/alipay-{date}.log`
2. 参考 [快速参考手册 - 调试技巧](./QUICK_REFERENCE.md#调试技巧)
3. 检查 [常见问题](./QUICK_REFERENCE.md#常见问题)

## 📖 核心概念

### 客户端检测

插件使用统一的 `ClientDetector` 模块检测用户环境:

```php
use WPKJFluentCart\Alipay\Detector\ClientDetector;

$type = ClientDetector::detect(); // 'alipay' | 'mobile' | 'pc'
```

详细说明: [模块化开发规范 - 客户端检测模块](./MODULE_STANDARDIZATION.md#客户端检测模块-clientdetector)

### 双模式订阅

插件支持两种订阅模式:

1. **自动续费模式**: 使用支付宝周期扣款协议
2. **手动续费模式**: 每次续费需要用户手动支付

详细说明: [订阅功能支持说明](../SUBSCRIPTION_SUPPORT.md)

### 智能降级策略

当自动续费失败时,系统自动降级到手动续费:

```
初始订阅
├─ 周期扣款已启用?
│  ├─ YES → 创建签约
│  │  ├─ 成功 → 签约页面
│  │  └─ 失败 → 降级到手动支付 ⬇️
│  └─ NO → 手动支付

续费支付
├─ 有活跃协议?
│  ├─ YES → 协议代扣
│  │  ├─ 成功 → 自动完成
│  │  └─ 失败 → 降级到手动支付 ⬇️
│  └─ NO → 手动支付
```

详细说明: [周期扣款配置指南](../RECURRING_PAYMENT_GUIDE.md)

## 🔧 开发规范

### 命名空间规范

所有代码必须在 `WPKJFluentCart\Alipay` 命名空间下:

```php
namespace WPKJFluentCart\Alipay\{模块名};

// 示例
namespace WPKJFluentCart\Alipay\Detector;
namespace WPKJFluentCart\Alipay\Processor;
namespace WPKJFluentCart\Alipay\Subscription;
```

### 模块职责

| 模块 | 职责 | 何时使用 |
|------|------|----------|
| `Detector\` | 客户端环境检测 | 需要判断用户设备类型时 |
| `Gateway\` | 支付网关集成 | 网关配置和初始化 |
| `Processor\` | 支付处理逻辑 | 处理支付、退款等操作 |
| `Subscription\` | 订阅功能 | 处理订阅相关逻辑 |
| `API\` | 支付宝 API | 调用支付宝接口 |
| `Config\` | 配置管理 | 获取配置值和常量 |
| `Utils\` | 工具类 | 日志、金额处理等工具 |
| `Services\` | 业务服务 | 编码、订单等业务逻辑 |
| `Webhook\` | 回调处理 | 处理支付宝通知 |

详细说明: [模块化开发规范](./MODULE_STANDARDIZATION.md)

### 代码风格

```php
// ✅ 推荐: 使用统一的模块
use WPKJFluentCart\Alipay\Detector\ClientDetector;
$type = ClientDetector::detect();

// ❌ 避免: 重复实现
if (wp_is_mobile()) { ... }

// ✅ 推荐: 使用常量
if ($length > AlipayConfig::MAX_SUBJECT_LENGTH) { ... }

// ❌ 避免: 硬编码
if ($length > 256) { ... }

// ✅ 推荐: 统一日志
Logger::info('Title', 'Content');

// ❌ 避免: 直接输出
error_log('...');
```

详细说明: [快速参考手册 - 最佳实践](./QUICK_REFERENCE.md#最佳实践)

## 📝 文档更新记录

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.0.0 | 2025-10-20 | 初始版本,包含完整的模块化文档 |

## 🤝 贡献指南

### 提交代码前检查

- [ ] 代码遵循命名空间规范
- [ ] 使用现有模块而非重复实现
- [ ] 添加了 PHPDoc 注释
- [ ] 添加了日志记录
- [ ] 进行了错误处理
- [ ] 更新了相关文档

### 文档贡献

欢迎完善文档!提交前请确保:

- 使用清晰的 Markdown 格式
- 代码示例可直接运行
- 链接引用正确
- 保持文档结构一致

## 📞 联系我们

- **开发者**: WPKJ Development Team
- **项目主页**: https://wpdaxue.com
- **技术支持**: 通过工单系统

## 📄 许可证

本插件遵循 GPL v2 或更高版本许可证。

---

**文档中心版本**: 1.0.0  
**最后更新**: 2025-10-20  
**维护者**: WPKJ Development Team
