<?php

namespace WPKJFluentCart\Alipay\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use WPKJFluentCart\Alipay\API\AlipayAPI;
use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Alipay Recurring Agreement Manager
 * 
 * 处理支付宝周期扣款协议（商家代扣）
 * 需要商家单独签约开通此功能
 * 
 * 支持的接口：
 * - alipay.user.agreement.page.sign - 周期扣款协议签约（页面）
 * - alipay.trade.pay - 协议支付（代扣）
 * - alipay.user.agreement.query - 查询协议状态
 * - alipay.user.agreement.unsign - 解约
 */
class AlipayRecurringAgreement
{
    /**
     * @var AlipaySettingsBase
     */
    private $settings;

    /**
     * @var AlipayAPI
     */
    private $api;

    /**
     * 产品码 - 周期扣款
     */
    const PRODUCT_CODE = 'CYCLE_PAY_AUTH';

    /**
     * Constructor
     * 
     * @param AlipaySettingsBase $settings
     */
    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
        $this->api = new AlipayAPI($settings);
    }

    /**
     * 检查商家是否开通周期扣款功能
     * 
     * @return bool
     */
    public function isRecurringEnabled()
    {
        // 检查配置中是否启用周期扣款
        $enabled = $this->settings->get('enable_recurring_agreement');
        
        if ($enabled !== 'yes') {
            Logger::info('Recurring Agreement Not Enabled', 'Setting is disabled in configuration');
            return false;
        }

        // 检查是否配置了签约产品码
        $personalProductCode = $this->settings->get('recurring_personal_product_code');
        
        if (empty($personalProductCode)) {
            Logger::warning('Recurring Agreement Product Code Missing', 'Product code not configured in settings');
            return false;
        }

        return true;
    }

    /**
     * 创建周期扣款签约页面
     * 
     * @param Subscription $subscription 订阅模型
     * @param array $orderData 订单数据
     * @return array|\WP_Error
     */
    public function createAgreementSign(Subscription $subscription, $orderData)
    {
        if (!$this->isRecurringEnabled()) {
            return new \WP_Error(
                'recurring_not_enabled',
                __('Recurring agreement feature is not enabled.', 'wpkj-fluentcart-alipay-payment')
            );
        }

        try {
            $order = $subscription->order;
            $customer = $order->customer;

            // 生成签约号（唯一标识）
            $externalAgreementNo = 'AGR_' . $subscription->id . '_' . time();

            // 构建签约参数
            $agreementParams = [
                'product_code' => self::PRODUCT_CODE,
                'external_agreement_no' => $externalAgreementNo,
                'sign_scene' => 'INDUSTRY|DIGITAL_MEDIA', // 签约场景：数字媒体
                'personal_product_code' => $this->settings->get('recurring_personal_product_code'),
                'sign_validity_period' => $this->calculateSignValidityPeriod($subscription),
                'period_rule_params' => $this->buildPeriodRuleParams($subscription)
            ];

            // 添加返回和通知URL
            $agreementParams['return_url'] = add_query_arg([
                'trx_hash' => $orderData['transaction_uuid'] ?? '',
                'fct_redirect' => 'yes',
                'agreement_sign' => '1'
            ], site_url('/'));

            $agreementParams['notify_url'] = add_query_arg([
                'fct_payment_listener' => '1',
                'method' => 'alipay',
                'action' => 'agreement'
            ], site_url('/'));

            Logger::info('Creating Recurring Agreement Sign', [
                'subscription_id' => $subscription->id,
                'external_agreement_no' => $externalAgreementNo,
                'billing_interval' => $subscription->billing_interval
            ]);

            // 调用签约接口
            $result = $this->api->makeRequest('alipay.user.agreement.page.sign', $agreementParams);

            if (is_wp_error($result)) {
                return $result;
            }

            // 保存签约信息到订阅元数据
            $subscription->updateMeta('alipay_agreement_no', $externalAgreementNo);
            $subscription->updateMeta('alipay_agreement_status', 'signing');
            $subscription->updateMeta('alipay_agreement_params', $agreementParams);

            return [
                'status' => 'success',
                'redirect_url' => $result['redirect_url'] ?? '',
                'external_agreement_no' => $externalAgreementNo
            ];

        } catch (\Exception $e) {
            Logger::error('Agreement Sign Creation Failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);

            return new \WP_Error('agreement_sign_failed', $e->getMessage());
        }
    }

    /**
     * 处理签约回调
     * 
     * @param array $data 回调数据
     * @return bool
     */
    public function handleAgreementCallback($data)
    {
        try {
            // 验证签名
            if (!$this->api->verifySignature($data)) {
                Logger::error('Agreement Callback Signature Verification Failed', $data);
                return false;
            }

            $externalAgreementNo = Arr::get($data, 'external_agreement_no');
            $agreementNo = Arr::get($data, 'agreement_no'); // 支付宝协议号
            $status = Arr::get($data, 'status'); // NORMAL-正常, STOP-暂停

            Logger::info('Agreement Callback Received', [
                'external_agreement_no' => $externalAgreementNo,
                'agreement_no' => $agreementNo,
                'status' => $status
            ]);

            // 通过 external_agreement_no 查找订阅
            $subscription = $this->findSubscriptionByAgreementNo($externalAgreementNo);

            if (!$subscription) {
                Logger::error('Subscription Not Found for Agreement', [
                    'external_agreement_no' => $externalAgreementNo
                ]);
                return false;
            }

            // 更新订阅的协议信息
            $subscription->updateMeta('alipay_agreement_no', $agreementNo);
            $subscription->updateMeta('alipay_agreement_status', $status === 'NORMAL' ? 'active' : 'stopped');
            $subscription->updateMeta('alipay_agreement_sign_time', current_time('mysql'));

            // 如果协议签约成功，更新订阅为自动续费模式
            if ($status === 'NORMAL') {
                $subscription->vendor_subscription_id = $agreementNo;
                $subscription->updateMeta('auto_renew_enabled', true);
                $subscription->save();

                Logger::info('Recurring Agreement Activated', [
                    'subscription_id' => $subscription->id,
                    'agreement_no' => $agreementNo
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Logger::error('Agreement Callback Processing Failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * 执行协议代扣（续费支付）
     * 
     * Uses FluentCart's SubscriptionService::recordRenewalPayment() for standard renewal handling
     * 
     * @param Subscription $subscription 订阅模型
     * @param int $amount 扣款金额（分）
     * @param array $orderData 订单数据
     * @return array|\WP_Error
     */
    public function executeAgreementPay(Subscription $subscription, $amount, $orderData)
    {
        try {
            $agreementNo = $subscription->vendor_subscription_id;

            if (empty($agreementNo)) {
                return new \WP_Error(
                    'no_agreement',
                    __('No recurring agreement found for this subscription.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            // 检查协议状态
            $agreementStatus = $subscription->getMeta('alipay_agreement_status');
            if ($agreementStatus !== 'active') {
                Logger::warning('Agreement Not Active', [
                    'subscription_id' => $subscription->id,
                    'agreement_status' => $agreementStatus
                ]);

                return new \WP_Error(
                    'agreement_not_active',
                    __('Recurring agreement is not active.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            // 生成商户订单号
            $outTradeNo = (Arr::get($orderData, 'out_trade_no')) ?: 
                          (str_replace('-', '', $orderData['transaction_uuid']) . '_' . time());

            // 构建代扣参数
            $payParams = [
                'out_trade_no' => $outTradeNo,
                'product_code' => 'CYCLE_PAY_AUTH_P',
                'total_amount' => Helper::toDecimal($amount),
                'subject' => $this->buildRenewalSubject($subscription),
                'agreement_params' => [
                    'agreement_no' => $agreementNo
                ]
            ];

            Logger::info('Executing Agreement Pay (Auto Renewal)', [
                'subscription_id' => $subscription->id,
                'agreement_no' => $agreementNo,
                'amount' => $amount,
                'out_trade_no' => $outTradeNo
            ]);

            // 调用协议支付接口
            $result = $this->api->makeRequest('alipay.trade.pay', $payParams);

            if (is_wp_error($result)) {
                Logger::error('Agreement Pay Failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $result->get_error_message()
                ]);
                return $result;
            }

            // 解析响应
            $response = Arr::get($result, 'alipay_trade_pay_response', []);
            $code = Arr::get($response, 'code');
            $tradeNo = Arr::get($response, 'trade_no');
            $buyerLogonId = Arr::get($response, 'buyer_logon_id', '');
            $buyerUserId = Arr::get($response, 'buyer_user_id', '');

            if ($code === '10000') {
                // 扣款成功 - 使用FluentCart标准方法记录续费支付
                Logger::info('Agreement Pay Success - Recording Renewal Payment', [
                    'subscription_id' => $subscription->id,
                    'trade_no' => $tradeNo,
                    'out_trade_no' => $outTradeNo
                ]);

                // 准备交易数据
                $transactionData = [
                    'subscription_id' => $subscription->id,
                    'vendor_charge_id' => $tradeNo,
                    'total' => $amount,
                    'status' => Status::TRANSACTION_SUCCEEDED,
                    'payment_method' => 'alipay',
                    'meta' => [
                        'alipay_trade_no' => $tradeNo,
                        'out_trade_no' => $outTradeNo,
                        'buyer_logon_id' => $buyerLogonId,
                        'buyer_user_id' => $buyerUserId,
                        'payment_type' => 'agreement_pay',
                        'agreement_no' => $agreementNo
                    ]
                ];

                // 准备订阅更新参数
                $subscriptionUpdateArgs = [
                    'next_billing_date' => $this->calculateNextBillingDate($subscription),
                    'status' => Status::SUBSCRIPTION_ACTIVE
                ];

                // 使用FluentCart标准方法 - 自动创建续费订单、订单项、交易记录并触发事件
                $createdTransaction = SubscriptionService::recordRenewalPayment(
                    $transactionData,
                    $subscription,
                    $subscriptionUpdateArgs
                );

                if (is_wp_error($createdTransaction)) {
                    Logger::error('Failed to record renewal payment via FluentCart', [
                        'subscription_id' => $subscription->id,
                        'error' => $createdTransaction->get_error_message()
                    ]);
                    return $createdTransaction;
                }

                Logger::info('Renewal Payment Recorded Successfully via FluentCart Standard Method', [
                    'subscription_id' => $subscription->id,
                    'transaction_id' => $createdTransaction->id,
                    'trade_no' => $tradeNo
                ]);

                return [
                    'status' => 'success',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => $outTradeNo,
                    'transaction_id' => $createdTransaction->id,
                    'message' => __('Renewal payment successful.', 'wpkj-fluentcart-alipay-payment')
                ];
            } else {
                // 扣款失败
                $errorMsg = Arr::get($response, 'sub_msg', Arr::get($response, 'msg', 'Unknown error'));
                
                Logger::error('Agreement Pay Failed (Business Error)', [
                    'subscription_id' => $subscription->id,
                    'code' => $code,
                    'message' => $errorMsg
                ]);

                return new \WP_Error('agreement_pay_failed', $errorMsg);
            }

        } catch (\Exception $e) {
            Logger::error('Agreement Pay Exception', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);

            return new \WP_Error('agreement_pay_exception', $e->getMessage());
        }
    }

    /**
     * Calculate next billing date for subscription
     * 
     * Uses FluentCart's built-in guessNextBillingDate() method for consistency
     * 
     * @param Subscription $subscription
     * @return string Y-m-d H:i:s format
     */
    private function calculateNextBillingDate(Subscription $subscription)
    {
        // Use FluentCart's built-in method which handles:
        // - Trial period calculation
        // - Interval-based date calculation
        // - Last order date tracking
        // - Edge cases and timezone handling
        return $subscription->guessNextBillingDate(true);
    }

    /**
     * 查询协议状态
     * 
     * @param string $agreementNo 支付宝协议号
     * @return array|\WP_Error
     */
    public function queryAgreement($agreementNo)
    {
        try {
            $params = [
                'agreement_no' => $agreementNo
            ];

            $result = $this->api->makeRequest('alipay.user.agreement.query', $params);

            if (is_wp_error($result)) {
                return $result;
            }

            $response = Arr::get($result, 'alipay_user_agreement_query_response', []);
            
            return [
                'status' => Arr::get($response, 'status'),
                'sign_time' => Arr::get($response, 'sign_time'),
                'valid_time' => Arr::get($response, 'valid_time'),
                'invalid_time' => Arr::get($response, 'invalid_time')
            ];

        } catch (\Exception $e) {
            Logger::error('Agreement Query Failed', [
                'agreement_no' => $agreementNo,
                'error' => $e->getMessage()
            ]);

            return new \WP_Error('query_failed', $e->getMessage());
        }
    }

    /**
     * 解约（取消协议）
     * 
     * @param Subscription $subscription 订阅模型
     * @return bool|\WP_Error
     */
    public function unsignAgreement(Subscription $subscription)
    {
        try {
            $agreementNo = $subscription->vendor_subscription_id;

            if (empty($agreementNo)) {
                return new \WP_Error(
                    'no_agreement',
                    __('No agreement found.', 'wpkj-fluentcart-alipay-payment')
                );
            }

            $params = [
                'agreement_no' => $agreementNo,
                'extend_params' => [
                    'operation_type' => 'TERMINATE' // 终止协议
                ]
            ];

            Logger::info('Unsigning Agreement', [
                'subscription_id' => $subscription->id,
                'agreement_no' => $agreementNo
            ]);

            $result = $this->api->makeRequest('alipay.user.agreement.unsign', $params);

            if (is_wp_error($result)) {
                return $result;
            }

            // 更新订阅元数据
            $subscription->updateMeta('alipay_agreement_status', 'terminated');
            $subscription->updateMeta('alipay_agreement_unsign_time', current_time('mysql'));
            $subscription->updateMeta('auto_renew_enabled', false);

            Logger::info('Agreement Unsigned Successfully', [
                'subscription_id' => $subscription->id
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Agreement Unsign Failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);

            return new \WP_Error('unsign_failed', $e->getMessage());
        }
    }

    /**
     * 计算签约有效期
     * 
     * @param Subscription $subscription
     * @return string
     */
    private function calculateSignValidityPeriod(Subscription $subscription)
    {
        // 如果有限制计费次数，计算总有效期
        if ($subscription->bill_times > 0) {
            $interval = $subscription->billing_interval;
            $times = $subscription->bill_times;

            // 计算总月数（粗略估算）
            $totalMonths = match($interval) {
                'day' => ceil($times / 30),
                'week' => ceil($times / 4),
                'month' => $times,
                'year' => $times * 12,
                default => $times
            };

            return $totalMonths . 'M'; // M表示月
        }

        // 无限次订阅，设置较长的有效期（10年）
        return '120M';
    }

    /**
     * 构建周期规则参数
     * 
     * @param Subscription $subscription
     * @return array
     */
    private function buildPeriodRuleParams(Subscription $subscription)
    {
        $interval = $subscription->billing_interval;
        $amount = Helper::toDecimal($subscription->recurring_total);

        // 映射 FluentCart 的计费周期到支付宝的周期类型
        $periodType = match($interval) {
            'day' => 'DAY',
            'week' => 'WEEK',
            'month' => 'MONTH',
            'year' => 'YEAR',
            default => 'MONTH'
        };

        return [
            'period_type' => $periodType,
            'period' => 1, // 每1个周期扣款
            'execute_time' => date('Y-m-d'), // 首次执行时间
            'single_amount' => $amount, // 单次扣款金额
            'total_amount' => $subscription->bill_times > 0 ? 
                             Helper::toDecimal($subscription->recurring_total * $subscription->bill_times) : 
                             '', // 总金额（无限次留空）
            'total_payments' => $subscription->bill_times > 0 ? $subscription->bill_times : '' // 总扣款次数
        ];
    }

    /**
     * 构建续费订单标题
     * 
     * @param Subscription $subscription
     * @return string
     */
    private function buildRenewalSubject(Subscription $subscription)
    {
        $order = $subscription->order;
        $items = $order->items ?? [];

        if (!empty($items[0])) {
            $productTitle = $items[0]['title'] ?? __('Subscription', 'wpkj-fluentcart-alipay-payment');
            return sprintf(
                /* translators: %s: product title */
                __('%s - Auto Renewal', 'wpkj-fluentcart-alipay-payment'),
                $productTitle
            );
        }

        return __('Subscription Auto Renewal', 'wpkj-fluentcart-alipay-payment');
    }

    /**
     * 通过签约号查找订阅
     * 
     * @param string $externalAgreementNo
     * @return Subscription|null
     */
    private function findSubscriptionByAgreementNo($externalAgreementNo)
    {
        // 从签约号提取订阅ID（格式: AGR_{subscription_id}_{timestamp}）
        if (preg_match('/^AGR_(\d+)_/', $externalAgreementNo, $matches)) {
            $subscriptionId = $matches[1];
            return Subscription::find($subscriptionId);
        }

        return null;
    }
}
