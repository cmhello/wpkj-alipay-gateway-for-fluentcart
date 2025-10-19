<?php

namespace WPKJFluentCart\Alipay\Detector;

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
     * Get appropriate payment method based on client environment
     * 
     * @return string alipay.trade.page.pay|alipay.trade.wap.pay|alipay.trade.app.pay
     */
    public static function getPaymentMethod(): string
    {
        // If in Alipay client, use app payment
        if (self::isAlipayClient()) {
            return 'alipay.trade.app.pay';
        }
        
        // If mobile browser, use WAP payment
        if (self::isMobile()) {
            return 'alipay.trade.wap.pay';
        }
        
        // Desktop browser, use page payment
        return 'alipay.trade.page.pay';
    }

    /**
     * Get user agent string
     * 
     * @return string
     */
    private static function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}
