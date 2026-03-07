<?php

namespace WPKJFluentCart\Alipay\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Renewal Checkout Page (Alipay)
 *
 * Mirrors the WeChat RenewalCheckoutPage but lives in the Alipay plugin.
 * Together they ensure the payment-method selector is rendered even when only
 * one of the two plugins is active.
 *
 * Both classes use the same once-flag constant (WPKJ_RENEWAL_CHECKOUT_PAGE_HANDLED)
 * so that when both plugins are active only the first one (by WordPress load
 * order) renders the page – but both still register their payment method via
 * the shared filter before the page renders.
 *
 *   add_filter('wpkj_fluentcart/renewal_checkout/payment_methods', $cb, 10, 2)
 *   function $cb(array $methods, Subscription $subscription): array
 *   Each item: ['key' => string, 'label' => string, 'icon_svg' => string, 'color' => string]
 */
class RenewalCheckoutPage
{
    /** @var AlipaySettingsBase */
    private $settings;

    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void
    {
        // Priority 5 – intercepts before FluentCart Pro's handler (priority 10).
        // Must hook on fluent_cart_action_reactivate-subscription because FluentCart
        // processes ?fluent-cart=... at init time (WebRoutes::registerRoutes) and die()s
        // before template_redirect ever fires.
        add_action('fluent_cart_action_reactivate-subscription', [$this, 'maybeRender'], 5);

        // Register Alipay as an available renewal payment method.
        add_filter('wpkj_fluentcart/renewal_checkout/payment_methods', [$this, 'registerAlipayMethod'], 10, 2);
    }

    /**
     * Register Alipay in the payment-method list.
     *
     * @param array        $methods
     * @param Subscription $subscription
     * @return array
     */
    public function registerAlipayMethod(array $methods, Subscription $subscription): array
    {
        if ($this->settings->get('is_active') !== 'yes') {
            return $methods;
        }

        $methods['alipay'] = [
            'key'      => 'alipay',
            'label'    => __('支付宝', 'wpkj-alipay-gateway-for-fluentcart'),
            'icon_svg' => $this->getAlipaySvg(),
            'color'    => '#1677ff',
        ];

        return $methods;
    }

    // -------------------------------------------------------------------------
    // template_redirect handler
    // -------------------------------------------------------------------------

    /**
     * Intercept reactivate-subscription requests that have no ?method= yet
     * and render the payment-method selector.
     *
     * @return void
     */
    public function maybeRender(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['fluent-cart']) || wp_unslash($_GET['fluent-cart']) !== 'reactivate-subscription') {
            return;
        }

        // If a payment method was already chosen, let the specific handler take over.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['method'])) {
            return;
        }

        // Use a constant as a once-flag so both WeChat + Alipay plugins don't
        // both try to render the page at the same time.
        if (defined('WPKJ_RENEWAL_CHECKOUT_PAGE_HANDLED')) {
            return;
        }
        define('WPKJ_RENEWAL_CHECKOUT_PAGE_HANDLED', true);

        // --- Resolve subscription UUID (supports both FluentCart ?subscription_hash and our ?subscription_uuid) ---
        // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
        $subscriptionUuid = '';
        if (!empty($_GET['subscription_hash'])) {
            $subscriptionUuid = sanitize_text_field(wp_unslash($_GET['subscription_hash']));
        } elseif (!empty($_GET['subscription_uuid'])) {
            $subscriptionUuid = sanitize_text_field(wp_unslash($_GET['subscription_uuid']));
        }
        // phpcs:enable

        if (empty($subscriptionUuid)) {
            $this->diePage(
                __('续费请求无效：缺少订阅标识符。', 'wpkj-alipay-gateway-for-fluentcart'),
                400
            );
        }

        // --- Login check ---
        if (!is_user_logged_in()) {
            $returnUrl = add_query_arg([
                'fluent-cart'       => 'reactivate-subscription',
                'subscription_hash' => $subscriptionUuid,
            ], home_url('/'));
            wp_redirect(wp_login_url($returnUrl));
            exit;
        }

        // --- Find subscription ---
        $subscription = Subscription::query()
            ->where('uuid', $subscriptionUuid)
            ->with(['product', 'variation', 'order', 'customer'])
            ->first();

        if (!$subscription) {
            $this->diePage(__('未找到该订阅。', 'wpkj-alipay-gateway-for-fluentcart'), 404);
        }

        // --- Ownership check ---
        $customer = $subscription->customer;
        if (!$customer || (int) $customer->user_id !== (int) get_current_user_id()) {
            Logger::warning('Renewal Checkout Unauthorized', [
                'subscription_id' => $subscription->id,
                'user_id'         => get_current_user_id(),
            ]);
            $this->diePage(__('您无权续费此订阅。', 'wpkj-alipay-gateway-for-fluentcart'), 403);
        }

        // --- Status check ---
        $renewableStatuses = [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_FAILING,
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_PAUSED,
            Status::SUBSCRIPTION_EXPIRING,
            Status::SUBSCRIPTION_PAST_DUE,
        ];

        if (!in_array($subscription->status, $renewableStatuses, true)) {
            $this->diePage(__('此订阅当前状态不支持续费。', 'wpkj-alipay-gateway-for-fluentcart'), 422);
        }

        // --- Collect available payment methods ---
        /** @var array<string,array{key:string,label:string,icon_svg:string,color:string}> $paymentMethods */
        $paymentMethods = (array) apply_filters(
            'wpkj_fluentcart/renewal_checkout/payment_methods',
            [],
            $subscription
        );

        if (empty($paymentMethods)) {
            $this->diePage(__('暂无可用的支付方式，请联系网站管理员。', 'wpkj-alipay-gateway-for-fluentcart'), 503);
        }

        // If only one method available, skip the selection page and redirect directly.
        if (count($paymentMethods) === 1) {
            $methodKey = (string) array_key_first($paymentMethods);
            wp_redirect(add_query_arg([
                'fluent-cart'       => 'reactivate-subscription',
                'subscription_hash' => $subscriptionUuid,
                'method'            => $methodKey,
            ], home_url('/')));
            exit;
        }

        Logger::info('Rendering Renewal Checkout Page (Alipay plugin)', [
            'subscription_id' => $subscription->id,
            'methods'         => array_keys($paymentMethods),
        ]);

        $this->renderPage($subscription, $subscriptionUuid, $paymentMethods);
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Output the complete HTML page.
     *
     * @param Subscription $subscription
     * @param string       $uuid
     * @param array        $paymentMethods
     * @return void
     */
    private function renderPage(Subscription $subscription, string $uuid, array $paymentMethods): void
    {
        $productName = $subscription->product
            ? $subscription->product->post_title
            : ($subscription->item_name ?? '');

        $variation = '';
        if ($subscription->variation && $subscription->product) {
            $variation = $subscription->variation->variation_title ?? '';
        }

        $renewalAmount   = (int) $subscription->getCurrentRenewalAmount();
        $formattedAmount = number_format($renewalAmount / 100, 2);
        $currency        = $subscription->currency ?? 'CNY';
        $currencySymbol  = $currency === 'CNY' ? '¥' : ($currency . ' ');
        $intervalLabel   = $this->getIntervalLabel($subscription->billing_interval ?? 'monthly');

        $storeName = get_bloginfo('name');
        $storeLogo = get_site_icon_url(64);

        // Base URL for all payment-method buttons (each appends ?method=xxx).
        $renewBaseUrl = add_query_arg([
            'fluent-cart'       => 'reactivate-subscription',
            'subscription_hash' => $uuid,
        ], home_url('/'));

        // Status badge config.
        $statusConfig = [
            Status::SUBSCRIPTION_ACTIVE    => ['🟢', __('续费订阅', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_TRIALING  => ['🟢', __('续费订阅', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_EXPIRED   => ['🔴', __('订阅已过期，续费后立即恢复服务', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_CANCELED  => ['⚪', __('订阅已取消，续费可重新激活', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_PAUSED    => ['🟡', __('订阅已暂停，续费即可恢复', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_FAILING   => ['🟠', __('上次续费失败，请重新支付', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_PAST_DUE  => ['🟠', __('账单已逾期，请尽快续费', 'wpkj-alipay-gateway-for-fluentcart')],
            Status::SUBSCRIPTION_EXPIRING  => ['🟡', __('订阅即将到期，请提前续费', 'wpkj-alipay-gateway-for-fluentcart')],
        ];
        [$statusIcon, $statusLabel] = $statusConfig[$subscription->status] ?? ['🔵', __('续费订阅', 'wpkj-alipay-gateway-for-fluentcart')];

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html($statusLabel . ' – ' . $productName); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Helvetica Neue', Arial, sans-serif;
    background: #f0f4f8;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    color: #2F3448;
}
.page-wrap { width: 100%; max-width: 440px; }

/* Header */
.site-header { text-align: center; margin-bottom: 24px; }
.site-logo { width: 48px; height: 48px; border-radius: 10px; margin-bottom: 8px; object-fit: contain; }
.site-name { font-size: 15px; color: #6b7280; font-weight: 500; }

/* Card */
.card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
    overflow: hidden;
}

/* Subscription summary */
.sub-summary { padding: 24px 24px 20px; border-bottom: 1px solid #f1f5f9; }
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 14px;
}
.sub-name { font-size: 18px; font-weight: 700; color: #1e293b; line-height: 1.3; }
.sub-variation { font-size: 13px; color: #94a3b8; margin-top: 4px; }
.price-row { display: flex; align-items: baseline; gap: 3px; margin-top: 16px; }
.price-symbol { font-size: 20px; font-weight: 600; color: #017EF3; }
.price-amount { font-size: 36px; font-weight: 700; color: #017EF3; letter-spacing: -1.5px; line-height: 1; }
.price-interval { font-size: 14px; color: #94a3b8; margin-left: 2px; }

/* Payment section */
.payment-section { padding: 20px 24px 24px; }
.section-title {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 14px;
}
.payment-list { display: flex; flex-direction: column; gap: 10px; }
.payment-btn {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    text-decoration: none;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.payment-btn:hover {
    border-color: #017EF3;
    box-shadow: 0 0 0 3px rgba(1,126,243,.12);
    background: #f8fbff;
}
.payment-btn:active { background: #eff8ff; }
.pm-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pm-label { font-size: 15px; font-weight: 600; flex: 1; }
.pm-arrow { color: #cbd5e1; font-size: 20px; font-weight: 300; transition: transform .15s, color .15s; }
.payment-btn:hover .pm-arrow { transform: translateX(3px); color: #017EF3; }

/* Footer */
.footer-links { text-align: center; margin-top: 20px; }
.back-link { font-size: 13px; color: #94a3b8; text-decoration: none; transition: color .15s; }
.back-link:hover { color: #64748b; }
.security-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    margin-top: 12px;
    font-size: 11px;
    color: #cbd5e1;
}
</style>
</head>
<body>
<div class="page-wrap">

    <!-- Site header -->
    <div class="site-header">
        <?php if ($storeLogo): ?>
        <img src="<?php echo esc_url($storeLogo); ?>" alt="<?php echo esc_attr($storeName); ?>" class="site-logo">
        <?php endif; ?>
        <div class="site-name"><?php echo esc_html($storeName); ?></div>
    </div>

    <!-- Main card -->
    <div class="card">

        <!-- Subscription summary -->
        <div class="sub-summary">
            <div class="status-badge">
                <span><?php echo esc_html($statusIcon); ?></span>
                <span><?php echo esc_html($statusLabel); ?></span>
            </div>
            <div class="sub-name"><?php echo esc_html($productName); ?></div>
            <?php if ($variation): ?>
            <div class="sub-variation"><?php echo esc_html($variation); ?></div>
            <?php endif; ?>
            <div class="price-row">
                <span class="price-symbol"><?php echo esc_html($currencySymbol); ?></span>
                <span class="price-amount"><?php echo esc_html($formattedAmount); ?></span>
                <span class="price-interval">/<?php echo esc_html($intervalLabel); ?></span>
            </div>
        </div>

        <!-- Payment method selection -->
        <div class="payment-section">
            <div class="section-title"><?php esc_html_e('选择支付方式', 'wpkj-alipay-gateway-for-fluentcart'); ?></div>
            <div class="payment-list">
                <?php foreach ($paymentMethods as $pm): ?>
                <a href="<?php echo esc_url(add_query_arg('method', $pm['key'], $renewBaseUrl)); ?>" class="payment-btn">
                    <div class="pm-icon" style="background:<?php echo esc_attr($pm['color'] ?? '#f1f5f9'); ?>">
                        <?php echo wp_kses($pm['icon_svg'] ?? '', ['svg' => ['width' => [], 'height' => [], 'viewBox' => [], 'fill' => [], 'xmlns' => []], 'path' => ['d' => [], 'fill' => [], 'fill-rule' => [], 'clip-rule' => []]]); ?>
                    </div>
                    <span class="pm-label"><?php echo esc_html($pm['label']); ?></span>
                    <span class="pm-arrow">›</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /.card -->

    <!-- Footer -->
    <div class="footer-links">
        <a href="javascript:history.back()" class="back-link">← <?php esc_html_e('返回上一页', 'wpkj-alipay-gateway-for-fluentcart'); ?></a>
        <div class="security-note">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?php esc_html_e('安全支付 · 信息加密传输', 'wpkj-alipay-gateway-for-fluentcart'); ?>
        </div>
    </div>

</div><!-- /.page-wrap -->
</body>
</html>
        <?php
        // phpcs:enable
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param string $interval
     * @return string
     */
    private function getIntervalLabel(string $interval): string
    {
        $labels = [
            'daily'       => __('天', 'wpkj-alipay-gateway-for-fluentcart'),
            'weekly'      => __('周', 'wpkj-alipay-gateway-for-fluentcart'),
            'monthly'     => __('月', 'wpkj-alipay-gateway-for-fluentcart'),
            'quarterly'   => __('季', 'wpkj-alipay-gateway-for-fluentcart'),
            'half_yearly' => __('半年', 'wpkj-alipay-gateway-for-fluentcart'),
            'yearly'      => __('年', 'wpkj-alipay-gateway-for-fluentcart'),
        ];
        return $labels[$interval] ?? $interval;
    }

    /**
     * Render an error page and exit.
     *
     * @param string $message
     * @param int    $status
     * @return never
     */
    private function diePage(string $message, int $status = 400): void
    {
        status_header($status);
        wp_die(
            esc_html($message),
            esc_html__('续费错误', 'wpkj-alipay-gateway-for-fluentcart'),
            ['response' => $status]
        );
    }

    /**
     * Alipay icon SVG (white logo on transparent background,
     * rendered inside a blue .pm-icon container).
     *
     * @return string
     */
    private function getAlipaySvg(): string
    {
        return '<svg width="26" height="26" viewBox="0 0 48 48" fill="white" xmlns="http://www.w3.org/2000/svg">'
             . '<path d="M24 4C12.954 4 4 12.954 4 24s8.954 20 20 20 20-8.954 20-20S35.046 4 24 4zm8.73 26.08c-2.56-.9-5.02-1.83-7.42-2.78 1.28-2.08 2.24-4.35 2.82-6.78h-7.45V19h9.05v-1.46H20.7V15h-3.4v2.54h-4.62V19h4.62v1.54H11v1.54h8.33c-.54 2.1-1.37 4.1-2.44 5.96a42.42 42.42 0 01-6.89-2.46v3.1c2.1.85 4.26 1.62 6.44 2.32C14.56 33.72 11.67 35 8 35.5v3.08c5.2-.65 9.26-3.07 12.02-6.2 2.7 1.06 5.37 2.08 7.98 3.02C26.1 37.03 22.3 38 18 38v2.95C26.73 40.88 32.5 37.5 35 31.5l-2.27-1.42z"/>'
             . '</svg>';
    }
}
