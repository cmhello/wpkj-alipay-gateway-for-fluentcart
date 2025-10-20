<?php

namespace WPKJFluentCart\Alipay\Config;

/**
 * Alipay Configuration Constants
 * 
 * Centralized configuration management for all Alipay-related constants
 * This eliminates magic numbers and improves code maintainability
 */
class AlipayConfig
{
    /**
     * Alipay API limits and constraints
     */
    const MAX_SINGLE_TRANSACTION_AMOUNT = 500000; // CNY
    const MAX_SUBJECT_LENGTH = 256;
    const MAX_BODY_LENGTH = 400;
    const MAX_OUT_TRADE_NO_LENGTH = 64;
    
    /**
     * Timeout settings
     */
    const PAYMENT_TIMEOUT_MINUTES = 30;
    const PAYMENT_TIMEOUT_BUFFER_MINUTES = 5;
    const DEFAULT_PAYMENT_TIMEOUT = '30m';
    
    /**
     * Cache TTL (Time To Live)
     */
    const NOTIFY_DEDUP_TTL = DAY_IN_SECONDS;
    const QUERY_CACHE_TTL = 5; // seconds
    
    /**
     * Polling settings for Face-to-Face payment
     */
    const STATUS_CHECK_INTERVAL = 3; // seconds
    const STATUS_CHECK_MAX_ATTEMPTS = 200; // ~10 minutes
    
    /**
     * Gateway URLs
     */
    const GATEWAY_URL_PROD = 'https://openapi.alipay.com/gateway.do';
    const GATEWAY_URL_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';
    
    /**
     * Supported currencies
     * 
     * @return array
     */
    public static function getSupportedCurrencies(): array
    {
        return [
            'CNY', 'USD', 'EUR', 'GBP', 'HKD', 'JPY', 'KRW', 
            'SGD', 'AUD', 'CAD', 'CHF', 'NZD', 'THB', 'MYR'
        ];
    }
    
    /**
     * Get payment timeout with buffer
     * 
     * @return int Total timeout in minutes
     */
    public static function getPaymentTimeoutWithBuffer(): int
    {
        return self::PAYMENT_TIMEOUT_MINUTES + self::PAYMENT_TIMEOUT_BUFFER_MINUTES;
    }
    
    /**
     * Get gateway URL based on mode
     * 
     * @param string $mode Payment mode (test/live)
     * @return string Gateway URL
     */
    public static function getGatewayUrl(string $mode): string
    {
        return $mode === 'test' ? self::GATEWAY_URL_SANDBOX : self::GATEWAY_URL_PROD;
    }
}
