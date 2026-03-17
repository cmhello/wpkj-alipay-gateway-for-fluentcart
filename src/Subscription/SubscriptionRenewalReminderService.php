<?php

namespace WPKJFluentCart\Alipay\Subscription;

use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Subscription Renewal Reminder Service (Alipay)
 *
 * Sends renewal-reminder emails to customers whose Alipay subscriptions
 * are expiring within 7 days.
 *
 * Designed to work alongside WAAS: if the WAAS plugin is active it hooks the
 * wpkj_fluentcart/alipay/renewal_reminder_enabled filter and returns false,
 * preventing duplicate emails for WAAS-managed subscriptions.
 *
 * Cron registration:
 *   - Hook:     wpkj_fc_alipay_renewal_reminder_daily
 *   - Schedule: daily
 *   - Registered via register() on the WordPress 'init' action.
 */
class SubscriptionRenewalReminderService
{
    /** WP-Cron hook name. */
    const CRON_HOOK = 'wpkj_fc_alipay_renewal_reminder_daily';

    /**
     * Register the cron schedule and cron callback.
     *
     * @return void
     */
    public function register(): void
    {
        // Schedule the daily cron event on activation / first run.
        add_action('init', [$this, 'scheduleCron']);

        // Bind the cron callback.
        add_action(self::CRON_HOOK, [$this, 'run']);
    }

    /**
     * Schedule the daily cron event if it is not already scheduled.
     *
     * @return void
     */
    public function scheduleCron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Run once a day starting at the next midnight (site timezone).
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Clear the scheduled cron event (call on plugin deactivation).
     *
     * @return void
     */
    public static function clearCron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    // -------------------------------------------------------------------------
    // Cron callback
    // -------------------------------------------------------------------------

    /**
     * Main cron entry point.
     *
     * Skips execution entirely if WAAS (or another plugin) disables reminders
     * via the wpkj_fluentcart/alipay/renewal_reminder_enabled filter.
     *
     * @return void
     */
    public function run(): void
    {
        /**
         * Allow external plugins (e.g. WAAS) to suppress Alipay renewal reminders.
         *
         * @param bool $enabled Whether to send reminders. Default true.
         */
        $enabled = (bool) apply_filters('wpkj_fluentcart/alipay/renewal_reminder_enabled', true);
        if (!$enabled) {
            Logger::info('Alipay renewal reminder skipped (disabled by filter)', '');
            return;
        }

        Logger::info('Alipay renewal reminder cron started', '');

        try {
            $this->sendReminders();
            $this->sendTrialExpiredReminders();
        } catch (\Exception $e) {
            Logger::error('Alipay renewal reminder cron exception', [
                'error' => $e->getMessage(),
            ]);
        }

        Logger::info('Alipay renewal reminder cron finished', '');
    }

    // -------------------------------------------------------------------------
    // Core logic
    // -------------------------------------------------------------------------

    /**
     * Query FluentCart subscriptions with payment_method = alipay that are
     * expiring within the next 7 days and send a reminder email for each.
     *
     * @return void
     */
    private function sendReminders(): void
    {
        global $wpdb;

        $fcSubs   = $wpdb->prefix . 'fct_subscriptions';
        $fcOrders = $wpdb->prefix . 'fct_orders';

        $lower = gmdate('Y-m-d H:i:s', time() + 6 * DAY_IN_SECONDS);
        $upper = gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.uuid, s.customer_id, s.item_name, s.next_billing_date,
                        s.status, c.user_id
                 FROM {$fcSubs} s
                 JOIN {$fcOrders} o ON o.id = s.parent_order_id
                 LEFT JOIN {$wpdb->prefix}fct_customers c ON c.id = s.customer_id
                 WHERE o.payment_method = 'alipay'
                   AND s.status IN ('active', 'trialing', 'expiring')
                   AND s.next_billing_date > %s
                   AND s.next_billing_date <= %s",
                $lower,
                $upper
            ),
            ARRAY_A
        );
        // phpcs:enable

        if (empty($rows)) {
            Logger::info('Alipay renewal reminder: no subscriptions expiring in 7 days', '');
            return;
        }

        Logger::info('Alipay renewal reminder: found subscriptions', ['count' => count($rows)]);

        foreach ($rows as $row) {
            $type = ($row['status'] === 'trialing') ? 'trial_ending' : 'renewal_due';
            $this->sendReminderEmail($row, $type);
        }
    }

    /**
     * Query FluentCart Alipay subscriptions in 'trialing' status whose trial just ended
     * (next_billing_date in the past 24 hours) and send a trial-expired reminder.
     *
     * Uses a 24-hour window so the email fires only once per trial expiry,
     * avoiding repeated notifications during the FC grace period.
     *
     * @return void
     */
    private function sendTrialExpiredReminders(): void
    {
        global $wpdb;

        $fcSubs   = $wpdb->prefix . 'fct_subscriptions';
        $fcOrders = $wpdb->prefix . 'fct_orders';

        $lower = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $upper = gmdate('Y-m-d H:i:s', time());

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.uuid, s.customer_id, s.item_name, s.next_billing_date,
                        s.status, c.user_id
                 FROM {$fcSubs} s
                 JOIN {$fcOrders} o ON o.id = s.parent_order_id
                 LEFT JOIN {$wpdb->prefix}fct_customers c ON c.id = s.customer_id
                 WHERE o.payment_method = 'alipay'
                   AND s.status = 'trialing'
                   AND s.next_billing_date >= %s
                   AND s.next_billing_date < %s",
                $lower,
                $upper
            ),
            ARRAY_A
        );
        // phpcs:enable

        if (empty($rows)) {
            return;
        }

        Logger::info('Alipay trial expired reminder: found subscriptions', ['count' => count($rows)]);

        foreach ($rows as $row) {
            $this->sendReminderEmail($row, 'trial_expired');
        }
    }

    /**
     * Send a single renewal-reminder email.
     *
     * @param array  $row  Row from the database query.
     * @param string $type Email type: 'renewal_due' | 'trial_ending' | 'trial_expired'.
     * @return void
     */
    private function sendReminderEmail(array $row, string $type = 'renewal_due'): void
    {
        $userId = (int) ($row['user_id'] ?? 0);
        if (!$userId) {
            Logger::warning('Alipay renewal reminder: no user_id for subscription', [
                'subscription_id' => $row['id'] ?? 0,
            ]);
            return;
        }

        $user = get_user_by('id', $userId);
        if (!$user) {
            return;
        }

        $subscriptionUuid = $row['uuid'] ?? '';
        $subscriptionName = $row['item_name'] ?? __('Your subscription', 'wpkj-alipay-gateway-for-fluentcart');
        $expiryDate       = $row['next_billing_date'] ?? '';
        $formattedExpiry  = $expiryDate
            ? date_i18n(get_option('date_format'), strtotime($expiryDate))
            : '';

        $renewUrl = '';
        if ($subscriptionUuid) {
            $renewUrl = add_query_arg([
                'fluent-cart'       => 'reactivate-subscription',
                'subscription_hash' => $subscriptionUuid,
            ], home_url('/'));
        }

        $siteName  = get_bloginfo('name');
        $userEmail = $user->user_email;

        switch ($type) {
            case 'trial_ending':
                /* translators: %s: site name */
                $subject = sprintf(
                    __('[%s] Your trial ends soon – please pay to continue', 'wpkj-alipay-gateway-for-fluentcart'),
                    $siteName
                );
                break;
            case 'trial_expired':
                /* translators: %s: site name */
                $subject = sprintf(
                    __('[%s] Your trial has ended – pay now to keep your service', 'wpkj-alipay-gateway-for-fluentcart'),
                    $siteName
                );
                break;
            default:
                /* translators: %s: site name */
                $subject = sprintf(
                    __('[%s] Your subscription is expiring – please renew', 'wpkj-alipay-gateway-for-fluentcart'),
                    $siteName
                );
        }

        $body = $this->buildEmailBody([
            'type'              => $type,
            'user_name'         => $user->display_name,
            'subscription_name' => $subscriptionName,
            'expiry_date'       => $formattedExpiry,
            'renew_url'         => $renewUrl,
            'site_name'         => $siteName,
        ]);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($userEmail, $subject, $body, $headers);

        Logger::info('Alipay renewal reminder email', [
            'subscription_id' => $row['id'] ?? 0,
            'user_id'         => $userId,
            'email'           => $userEmail,
            'type'            => $type,
            'sent'            => $sent,
        ]);
    }

    /**
     * Build the HTML email body.
     *
     * @param array{type:string,user_name:string,subscription_name:string,expiry_date:string,renew_url:string,site_name:string} $data
     * @return string
     */
    private function buildEmailBody(array $data): string
    {
        $type             = $data['type'] ?? 'renewal_due';
        $userName         = esc_html($data['user_name']);
        $subscriptionName = esc_html($data['subscription_name']);
        $expiryDate       = esc_html($data['expiry_date']);
        $renewUrl         = esc_url($data['renew_url']);
        $siteName         = esc_html($data['site_name']);

        switch ($type) {
            case 'trial_ending':
                $bodyLine  = esc_html__('Your trial for the subscription below will expire soon. Please pay before the trial ends to continue using the service.', 'wpkj-alipay-gateway-for-fluentcart');
                $btnColor  = '#1677ff';
                $btnLabel  = esc_html__('Pay Now to Continue', 'wpkj-alipay-gateway-for-fluentcart');
                $noteLabel = esc_html__('If you have already paid, please ignore this email.', 'wpkj-alipay-gateway-for-fluentcart');
                break;
            case 'trial_expired':
                $bodyLine  = esc_html__('Your trial has ended. Please complete payment within the grace period to keep your service running.', 'wpkj-alipay-gateway-for-fluentcart');
                $btnColor  = '#e53e3e';
                $btnLabel  = esc_html__('Pay Now – Grace Period Active', 'wpkj-alipay-gateway-for-fluentcart');
                $noteLabel = esc_html__('Your service will be suspended once the grace period ends.', 'wpkj-alipay-gateway-for-fluentcart');
                break;
            default:
                $bodyLine  = esc_html__('Your subscription below is expiring soon. Please renew to avoid service interruption.', 'wpkj-alipay-gateway-for-fluentcart');
                $btnColor  = '#1677ff';
                $btnLabel  = esc_html__('Renew Now', 'wpkj-alipay-gateway-for-fluentcart');
                $noteLabel = esc_html__('If you have already renewed, please ignore this email.', 'wpkj-alipay-gateway-for-fluentcart');
        }

        $expiryText = $expiryDate
            /* translators: %s: expiry date */
            ? '<p style="margin:8px 0 0;font-size:14px;color:#64748b;">' . sprintf(esc_html__('Date: %s', 'wpkj-alipay-gateway-for-fluentcart'), $expiryDate) . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Helvetica Neue',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 0;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
  <!-- Header -->
  <tr><td align="center" style="padding-bottom:24px;">
    <span style="font-size:15px;color:#6b7280;font-weight:500;">{$siteName}</span>
  </td></tr>
  <!-- Card -->
  <tr><td style="background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="padding:32px 32px 24px;border-bottom:1px solid #f1f5f9;">
        <p style="margin:0 0 12px;font-size:16px;color:#1e293b;">Hi, {$userName}</p>
        <p style="margin:0;font-size:15px;color:#475569;line-height:1.6;">{$bodyLine}</p>
        <p style="margin:12px 0 0;font-size:15px;font-weight:600;color:#1e293b;">{$subscriptionName}</p>
        {$expiryText}
      </td></tr>
      <tr><td style="padding:28px 32px 32px;text-align:center;">
        <a href="{$renewUrl}" style="display:inline-block;padding:13px 36px;background:{$btnColor};color:#fff;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;">{$btnLabel}</a>
        <p style="margin:20px 0 0;font-size:12px;color:#94a3b8;">{$noteLabel}</p>
      </td></tr>
    </table>
  </td></tr>
  <!-- Footer -->
  <tr><td align="center" style="padding:20px 0;">
    <span style="font-size:11px;color:#cbd5e1;">{$siteName}</span>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
