# FluentCart 支付宝订阅支付支持

## 概述

支付宝支付插件现已完全支持 FluentCart 的订阅功能。本插件实现了订阅支付的完整生命周期管理，提供两种订阅模式：

1. **自动续费模式**（周期扣款协议）- 推荐 ✨
2. **手动续费模式**（备选方案）

## 重要说明

### 支付宝周期扣款功能

支付宝提供**商家代扣**（周期扣款协议）功能，适用于自动续费场景：
- 会员费自动续费
- 周期租赁费
- 定期还款
- 定期缴费

**工作原理**：
- 用户首次购买时签订扣款协议
- 约定扣款周期、间隔时间、金额
- 商家获取协议号
- 到期自动扣款，无需用户手动操作

**签约要求**：
- ⚠️ 需要商家单独申请开通此功能
- 产品码：`GENERAL_WITHHOLDING_P` 或其他支付宝分配的产品码
- 未开通时自动降级到手动续费模式

### 实施策略

### 实施策略

本插件采用**智能降级策略**：

```
启用周期扣款？
    ├─ 是 → 使用自动续费模式（优先）
    │        └─ 失败？→ 降级到手动续费
    └─ 否 → 使用手动续费模式
```

## 两种订阅模式对比

| 特性 | 自动续费模式 | 手动续费模式 |
|-----|------------|-------------|
| 用户体验 | ⭐⭐⭐⭐⭐ 自动扣款 | ⭐⭐⭐ 需手动支付 |
| 续费成功率 | 高 | 中等 |
| 开通要求 | 需签约周期扣款 | 无要求 |
| 适用场景 | 长期订阅、会员服务 | 灵活订阅、临时订阅 |
| 用户操作 | 首次签约即可 | 每次续费需支付 |

## 配置指南

### 启用自动续费（周期扣款）

1. **申请开通**：
   - 登录支付宝开放平台
   - 申请「周期扣款」产品功能
   - 等待审核通过
   - 获取产品码（如 `GENERAL_WITHHOLDING_P`）

2. **插件配置**：
   ```
   FluentCart → Settings → Payments → Alipay
   
   ✅ Enable automatic recurring payment via Alipay agreement
   Personal Product Code: GENERAL_WITHHOLDING_P
   ```

3. **验证配置**：
   - 创建测试订阅产品
   - 完成首次购买
   - 检查是否跳转到协议签约页面
   - 签约成功后查看订阅元数据中的 `alipay_agreement_status`

### 使用手动续费（默认）

## 技术架构

### 核心组件

### 1. AlipayRecurringAgreement 类
**文件位置**: `src/Subscription/AlipayRecurringAgreement.php`

**功能**：
- 处理周期扣款协议的全生命周期
- 创建协议签约页面
- 执行协议代扣（自动续费）
- 查询协议状态
- 解约处理

**关键方法**：
```php
// 检查商家是否开通周期扣款
public function isRecurringEnabled()

// 创建签约页面
public function createAgreementSign(Subscription $subscription, $orderData)

// 执行协议代扣
public function executeAgreementPay(Subscription $subscription, $amount, $orderData)

// 查询协议状态
public function queryAgreement($agreementNo)

// 解约
public function unsignAgreement(Subscription $subscription)
```

#### 2. AlipaySubscriptions 类
**文件位置**: `src/Subscription/AlipaySubscriptions.php`

**功能**：
- 继承 `AbstractSubscriptionModule` 抽象类
- 实现订阅生命周期管理方法
- 处理订阅取消、重新激活等操作
- 从支付宝同步订阅状态

**关键方法**：
```php
// 从支付宝同步订阅状态
public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)

// 取消订阅
public function cancel($vendorSubscriptionId, $args = [])

// 取消自动续费
public function cancelAutoRenew($subscription)

// 重新激活订阅
public function reactivateSubscription($data, $subscriptionId)
```

#### 2. AlipaySubscriptionProcessor 类
**文件位置**: `src/Subscription/AlipaySubscriptionProcessor.php`

**功能**：
- 处理订阅支付流程
- 计算初始支付和续费支付金额
- 生成订阅支付参数
- 支持 PC、移动端、当面付等多种支付方式

**支付金额计算逻辑**：
```php
// 初始支付
if ($order->type === 'initial') {
    // 有试用期：仅收取设置费
    if ($subscription->trial_days > 0) {
        $amount = $subscription->signup_fee;
    } else {
        // 无试用期：设置费 + 首期费用
        $amount = $subscription->signup_fee + $subscription->recurring_total;
    }
}

// 续费支付
if ($order->type === 'renewal') {
    $amount = $subscription->getCurrentRenewalAmount();
}
```

#### 3. NotifyHandler 扩展
**文件位置**: `src/Webhook/NotifyHandler.php`

**新增功能**：
- 识别订阅支付通知
- 处理订阅支付成功回调
- 更新订阅状态和计费计数
- 计算下次计费日期
- 处理订阅完成逻辑（有限次数订阅）

**关键方法**：
```php
// 检查是否为订阅交易
private function isSubscriptionTransaction($transaction)

// 处理订阅支付成功
private function handleSubscriptionPaymentSuccess($transaction, $data)
```

#### 4. AlipayGateway 集成
**文件位置**: `src/Gateway/AlipayGateway.php`

**修改内容**：
- 添加 `subscriptions` 到 `supportedFeatures` 数组
- 在构造函数中注入 `AlipaySubscriptions` 实例
- 修改 `makePaymentFromPaymentInstance()` 方法，支持订阅支付路由

```php
public array $supportedFeatures = ['payment', 'refund', 'webhook', 'subscriptions'];

public function __construct()
{
    $settings = new AlipaySettingsBase();
    $subscriptions = new AlipaySubscriptions($settings);
    
    parent::__construct($settings, $subscriptions);
}
```

## 订阅支付流程

### 1. 初始订阅创建

```
用户购买订阅产品
    ↓
FluentCart 创建订阅记录
    ↓
调用 AlipaySubscriptionProcessor::processSubscription()
    ↓
计算首次支付金额（设置费 + 首期费用，或仅设置费如有试用期）
    ↓
生成支付宝订单号（包含时间戳）
    ↓
调用支付宝支付接口（PC/Mobile/当面付）
    ↓
用户完成支付
    ↓
支付宝异步通知
    ↓
NotifyHandler 处理
    ↓
更新订阅状态为 ACTIVE
```

### 2. 续费支付流程

```
FluentCart 定时任务检测到续费时间
    ↓
创建 renewal 类型订单
    ↓
调用 AlipaySubscriptionProcessor::processSubscription()
    ↓
计算续费金额
    ↓
生成新的支付宝订单
    ↓
发送续费通知给用户（邮件/短信）
    ↓
用户完成支付
    ↓
支付宝异步通知
    ↓
NotifyHandler 处理
    ↓
更新订阅:
  - bill_count + 1
  - 计算下次计费日期
  - 检查是否达到最大计费次数
```

### 3. 订阅取消流程

```
用户/管理员取消订阅
    ↓
FluentCart 调用 AlipaySubscriptions::cancel()
    ↓
更新本地订阅状态为 CANCELED
    ↓
设置 canceled_at 时间戳
    ↓
停止后续自动续费
```

## 订阅状态映射

| 支付宝交易状态 | FluentCart 订阅状态 | 说明 |
|--------------|-------------------|------|
| TRADE_SUCCESS | SUBSCRIPTION_ACTIVE | 支付成功，订阅激活 |
| TRADE_FINISHED | SUBSCRIPTION_ACTIVE | 交易完成，订阅激活 |
| WAIT_BUYER_PAY | SUBSCRIPTION_PENDING | 等待付款 |
| TRADE_CLOSED | SUBSCRIPTION_CANCELED | 交易关闭，订阅取消 |

## 支持的功能

### ✅ 已实现

- [x] 初始订阅支付（含设置费）
- [x] 试用期支持
- [x] 续费支付处理
- [x] 订阅状态同步
- [x] 订阅取消
- [x] 订阅重新激活
- [x] 计费次数统计
- [x] 有限次数订阅（自动完成）
- [x] 无限次数订阅
- [x] 多种支付方式（PC网页/移动WAP/当面付）
- [x] 订阅支付通知处理
- [x] 下次计费日期计算
- [x] 计费周期支持（天/周/月/年）

### ⚠️ 注意事项

1. **自动续费限制**：
   - 支付宝不支持自动扣款，每次续费需要用户手动支付
   - 建议通过邮件/短信提前通知用户续费

2. **续费提醒**：
   - 使用 FluentCart 或 FluentCRM 的邮件系统
   - 在续费到期前 7 天、3 天、1 天发送提醒

3. **支付失败处理**：
   - FluentCart 会自动重试失败的续费
   - 可配置重试次数和重试间隔

4. **订阅完成**：
   - 达到最大计费次数后自动完成
   - `bill_count >= bill_times` 时状态变为 COMPLETED

## 测试指南

### 1. 创建测试订阅产品

在 FluentCart 中创建一个订阅产品：
- 设置费：¥10
- 周期费用：¥50/月
- 试用期：7 天（可选）
- 计费次数：12 次（可选，留空为无限次）

### 2. 测试初始购买

```
1. 前端购买订阅产品
2. 选择支付宝支付
3. 完成支付
4. 验证：
   - 订阅状态为 ACTIVE
   - 下次计费日期已设置
   - bill_count = 0（或 1，取决于是否有试用期）
```

### 3. 测试续费

```
1. 修改订阅的 next_billing_date 为当前时间
2. 手动触发 FluentCart 的续费 cron
3. 验证：
   - 创建了新的 renewal 订单
   - 用户收到续费通知
   - 完成支付后 bill_count 增加
   - next_billing_date 更新
```

### 4. 测试取消订阅

```
1. 在订阅管理页面取消订阅
2. 验证：
   - 订阅状态变为 CANCELED
   - canceled_at 时间已设置
   - 不再创建新的续费订单
```

## 日志追踪

订阅相关的所有操作都会记录详细日志，可在以下文件中查看：

```
/wp-content/debug.log
```

关键日志搜索关键词：
- `Processing Alipay Subscription Payment`
- `Subscription Payment Amount Calculated`
- `Subscription Activated`
- `Next Billing Date Updated`
- `Subscription Completed`

## 性能优化

1. **缓存机制**：订阅查询结果缓存 5 秒
2. **重复通知防护**：使用 transient 防止重复处理通知
3. **异步处理**：所有 Webhook 通知异步处理，不阻塞支付宝回调

## 安全措施

1. **签名验证**：所有支付宝通知都进行签名验证
2. **CSRF 防护**：使用 WordPress nonce 机制
3. **UUID 验证**：严格验证订单号格式
4. **重放攻击防护**：使用 notify_id 防止重复处理

## 兼容性

- **FluentCart**: 1.2.0+
- **WordPress**: 6.5+
- **PHP**: 8.2+
- **支付宝**: 所有支付方式（PC网页、移动WAP、当面付）

## 升级说明

从旧版本升级时，现有的一次性支付不受影响。订阅功能作为新增功能，可以与现有支付并存。

### 升级步骤

1. 更新插件文件
2. 清除 WordPress 对象缓存（如使用）
3. 在 FluentCart 设置中验证支付宝配置
4. 创建测试订阅产品进行验证

## 常见问题

### Q: 支付宝支持自动扣款吗？
A: 不支持。支付宝的企业支付接口不提供订阅/周期性自动扣款功能。每次续费都需要用户手动完成支付。

### Q: 如何提醒用户续费？
A: 使用 FluentCart 的邮件系统或集成 FluentCRM，在续费到期前发送提醒邮件。

### Q: 续费失败怎么办？
A: FluentCart 会自动重试失败的续费。可在设置中配置重试次数和间隔。订阅会进入 PENDING 或 PAST_DUE 状态。

### Q: 可以修改订阅周期吗？
A: 可以。创建新订阅替换旧订阅，FluentCart 会自动处理升级/降级逻辑。

### Q: 支持多种计费周期吗？
A: 支持。可设置为：天（day）、周（week）、月（month）、年（year）。

## 技术支持

如有问题，请通过以下方式联系：

- 邮箱：support@wpdaxue.com
- 网站：https://www.wpdaxue.com

## 版本历史

### v1.1.0 (2025-10-20)
- ✨ 新增：完整的订阅支付支持
- ✨ 新增：AlipaySubscriptions 订阅模块
- ✨ 新增：AlipaySubscriptionProcessor 处理器
- ✨ 改进：NotifyHandler 支持订阅状态同步
- ✨ 改进：AlipayGateway 集成订阅功能
- 📝 文档：添加订阅功能完整文档

### v1.0.4
- 初始版本，支持一次性支付
