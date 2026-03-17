<?php

namespace WPKJFluentCart\Alipay\Utils;

use FluentCart\App\Helpers\Helper as FluentCartHelper;

/**
 * Helper Utility
 * 
 * General helper functions for Alipay gateway
 */
class Helper
{
    /**
     * Convert cents to decimal amount
     * 
     * @param int $cents Amount in cents
     * @return string Decimal amount
     */
    public static function toDecimal($cents)
    {
        return FluentCartHelper::toDecimalWithoutComma($cents);
    }

    /**
     * Convert decimal to cents
     * 
     * @param float $amount Decimal amount
     * @return int Amount in cents
     */
    public static function toCents($amount)
    {
        return FluentCartHelper::toCent($amount);
    }

    /**
     * Format Alipay private key
     * 
     * @param string $key Private key
     * @return string Formatted private key
     */
    public static function formatPrivateKey($key)
    {
        $key = trim($key);
        
        // Remove header and footer if present
        $key = str_replace(['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----'], '', $key);
        $key = str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'], '', $key);
        $key = str_replace(["\r", "\n", ' '], '', $key);
        
        // Add header and footer
        return "-----BEGIN RSA PRIVATE KEY-----\n" . 
               wordwrap($key, 64, "\n", true) . 
               "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * Format Alipay public key
     * 
     * @param string $key Public key
     * @return string Formatted public key
     */
    public static function formatPublicKey($key)
    {
        $key = trim($key);
        
        // Remove header and footer if present
        $key = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $key);
        $key = str_replace(["\r", "\n", ' '], '', $key);
        
        // Add header and footer
        return "-----BEGIN PUBLIC KEY-----\n" . 
               wordwrap($key, 64, "\n", true) . 
               "\n-----END PUBLIC KEY-----";
    }

    /**
     * Generate out_trade_no (order number for Alipay)
     * 
     * IMPORTANT: Alipay requires out_trade_no to be unique for each payment attempt.
     * Even if it's the same product/customer, each payment must have a unique out_trade_no.
     * 
     * Format: {transaction_uuid_without_dashes}_{timestamp_microseconds}
     * This ensures absolute uniqueness even for rapid repeated orders.
     * 
     * @param string $transactionUuid Transaction UUID
     * @return string Unique trade number (max 64 chars)
     */
    public static function generateOutTradeNo($transactionUuid)
    {
        // Remove dashes from UUID (32 chars)
        $baseUuid = str_replace('-', '', $transactionUuid);
        
        // Add microsecond timestamp to ensure uniqueness (17 chars)
        // This prevents duplicate out_trade_no even if transaction UUID is somehow reused
        $uniqueSuffix = microtime(true) * 10000; // Convert to integer to avoid decimals
        $uniqueSuffix = substr(str_replace('.', '', (string)$uniqueSuffix), 0, 17);
        
        // Total length: 32 + 1 + 17 = 50 chars (well under Alipay's 64 char limit)
        return $baseUuid . '_' . $uniqueSuffix;
    }

    /**
     * Sanitize Alipay response data
     * 
     * @param array $data Response data
     * @return array Sanitized data
     */
    public static function sanitizeResponseData($data)
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeResponseData($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        
        return $sanitized;
    }

    /**
     * Check if HTTPS is enabled
     * 
     * @return bool
     */
    public static function isHttps()
    {
        return is_ssl();
    }

    /**
     * Get current site URL with protocol
     * 
     * @return string
     */
    public static function getSiteUrl()
    {
        return home_url('/');
    }

    /**
     * Format amount for display
     * 
     * @param int $cents Amount in cents
     * @param string $currency Currency code
     * @return string Formatted amount with currency symbol
     */
    public static function formatAmount($cents, $currency = 'CNY')
    {
        $amount = self::toDecimal($cents);
        
        $currencySymbols = [
            'CNY' => '¥',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'HKD' => 'HK$',
            'KRW' => '₩',
            'SGD' => 'S$',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'CHF' => 'CHF',
            'NZD' => 'NZ$',
            'THB' => '฿',
            'MYR' => 'RM'
        ];
        
        $symbol = $currencySymbols[$currency] ?? $currency . ' ';
        
        return $symbol . number_format((float)$amount, 2, '.', ',');
    }
}
