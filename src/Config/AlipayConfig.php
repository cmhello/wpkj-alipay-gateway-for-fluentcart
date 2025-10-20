<?php

namespace WPKJFluentCart\Alipay\Config;

/**
 * Alipay Configuration Constants
 * 
 * Centralized configuration management for all Alipay-related constants.
 * This eliminates magic numbers and improves code maintainability.
 * 
 * All limits and constraints are based on Alipay official documentation:
 * @link https://opendocs.alipay.com/open/270/105899
 * 
 * @package WPKJFluentCart\Alipay\Config
 * @since 1.0.4
 */
class AlipayConfig
{
    /**
     * Alipay API limits and constraints
     */
    
    /**
     * Minimum payment amount (in cents)
     * 
     * Alipay requires minimum 0.01 CNY (1 cent) for payment.
     * This prevents invalid zero-amount payments.
     * 
     * @var int
     */
    const MIN_PAYMENT_AMOUNT_CENTS = 1;
    
    /**
     * Maximum single transaction amount limit (CNY)
     * 
     * Alipay enforces this limit on all payment requests.
     * Exceeding this limit will result in payment failure.
     * 
     * @link https://opendocs.alipay.com/open/270/105899
     * @var int
     */
    const MAX_SINGLE_TRANSACTION_AMOUNT = 500000;
    
    /**
     * Maximum length for payment subject/title
     * 
     * Used in biz_content.subject parameter.
     * 
     * @var int
     */
    const MAX_SUBJECT_LENGTH = 256;
    
    /**
     * Maximum length for payment body/description
     * 
     * Used in biz_content.body parameter.
     * 
     * @var int
     */
    const MAX_BODY_LENGTH = 400;
    
    /**
     * Maximum length for out_trade_no (merchant order number)
     * 
     * Alipay requires out_trade_no to be unique and within this length.
     * 
     * @var int
     */
    const MAX_OUT_TRADE_NO_LENGTH = 64;
    
    /**
     * Timeout settings
     */
    
    /**
     * Payment timeout in minutes
     * 
     * After this time, unpaid orders will be automatically cancelled by Alipay.
     * 
     * @var int
     */
    const PAYMENT_TIMEOUT_MINUTES = 30;
    
    /**
     * Payment timeout buffer in minutes
     * 
     * Additional buffer time for timeout processing.
     * Used when checking for expired transactions.
     * 
     * @var int
     */
    const PAYMENT_TIMEOUT_BUFFER_MINUTES = 5;
    
    /**
     * Default payment timeout string
     * 
     * Format used in Alipay API biz_content.timeout_express parameter.
     * 
     * @var string
     */
    const DEFAULT_PAYMENT_TIMEOUT = '30m';
    
    /**
     * Cache TTL (Time To Live)
     */
    
    /**
     * Notify deduplication cache TTL
     * 
     * Prevents duplicate processing of Alipay notify callbacks.
     * Uses WordPress transient API.
     * 
     * @var int Seconds (86400 = 24 hours)
     */
    const NOTIFY_DEDUP_TTL = DAY_IN_SECONDS;
    
    /**
     * Query result cache TTL
     * 
     * Caches Alipay trade query results to reduce API calls.
     * Short TTL ensures near-real-time status updates.
     * 
     * @var int Seconds
     */
    const QUERY_CACHE_TTL = 5;
    
    /**
     * Polling settings for Face-to-Face payment
     */
    
    /**
     * Status check interval in seconds
     * 
     * How often to poll Alipay for payment status on F2F payment page.
     * 
     * @var int Seconds
     */
    const STATUS_CHECK_INTERVAL = 3;
    
    /**
     * Maximum status check attempts
     * 
     * Maximum number of status checks before timing out.
     * (200 attempts × 3 seconds = 600 seconds = 10 minutes)
     * 
     * @var int
     */
    const STATUS_CHECK_MAX_ATTEMPTS = 200;
    
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
