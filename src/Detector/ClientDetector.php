<?php

namespace WPKJFluentCart\Alipay\Detector;

use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Client Detector
 * 
 * Detects user environment to choose appropriate Alipay payment interface
 */
class ClientDetector
{
    /**
     * Detect if user is in Alipay client
     * 
     * @return bool
     */
    public static function isAlipayClient(): bool
    {
        $userAgent = self::getUserAgent();
        
        if (empty($userAgent)) {
            return false;
        }
        
        return stripos($userAgent, 'AlipayClient') !== false;
    }

    /**
     * Detect if user is on mobile device
     * 
     * @return bool
     */
    public static function isMobile(): bool
    {
        $userAgent = self::getUserAgent();
        
        // Handle empty user agent
        if (empty($userAgent)) {
            return false;
        }
        
        $mobileAgents = [
            'Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 
            'Windows Phone', 'Mobile', 'Symbian', 'Opera Mini'
        ];
        
        foreach ($mobileAgents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Detect client type
     * 
     * Returns simplified client type string for payment routing
     * 
     * @return string 'alipay'|'mobile'|'pc'
     */
    public static function detect(): string
    {
        if (self::isAlipayClient()) {
            return 'alipay';
        }
        
        if (self::isMobile()) {
            return 'mobile';
        }
        
        return 'pc';
    }

    /**
     * Get appropriate payment method based on client environment
     * 
     * @param AlipaySettingsBase|null $settings Settings instance (optional)
     * @return string alipay.trade.page.pay|alipay.trade.wap.pay|alipay.trade.app.pay|alipay.trade.precreate
     */
    public static function getPaymentMethod($settings = null): string
    {
        if (self::isAlipayClient()) {
            return 'alipay.trade.app.pay';
        }
        
        if (self::isMobile()) {
            return 'alipay.trade.wap.pay';
        }
        
        if ($settings && $settings->get('enable_face_to_face_pc') === 'yes') {
            return 'alipay.trade.precreate';
        }
        
        return 'alipay.trade.page.pay';
    }

    /**
     * Get user agent string
     * 
     * @return string
     */
    private static function getUserAgent(): string
    {
        // Check if HTTP_USER_AGENT exists (may not in CLI or proxied environments)
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            Logger::info('User Agent Not Available', [
                'environment' => php_sapi_name(),
                'is_cli' => php_sapi_name() === 'cli'
            ]);
            return '';
        }
        
        return $_SERVER['HTTP_USER_AGENT'];
    }
}
