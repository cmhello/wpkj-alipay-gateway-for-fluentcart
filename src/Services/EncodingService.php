<?php

namespace WPKJFluentCart\Alipay\Services;

use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Encoding Service
 * 
 * Centralized service for character encoding operations
 * This ensures consistent UTF-8 encoding across the plugin and prevents
 * Chinese character garbling issues in Alipay payment interface.
 * 
 * Key features:
 * - Automatic encoding detection and conversion
 * - BOM (Byte Order Mark) removal
 * - Special character filtering
 * - Support for common Chinese encodings (GBK, GB2312, GB18030)
 */
class EncodingService
{
    /**
     * Supported source encodings for detection
     * 
     * @var array
     */
    private static array $supportedEncodings = [
        'UTF-8',      // Standard UTF-8
        'GB2312',     // Simplified Chinese GB2312
        'GBK',        // Simplified Chinese GBK (extended GB2312)
        'GB18030',    // Chinese National Standard
        'BIG5',       // Traditional Chinese
        'ISO-8859-1', // Latin-1
        'ASCII'       // ASCII
    ];
    
    /**
     * Ensure string is valid UTF-8 encoded
     * 
     * This is the primary method for ensuring Chinese characters display correctly.
     * It handles various encoding issues that can cause garbled text:
     * 
     * 1. Detects actual encoding (GBK, GB2312, GB18030, BIG5, etc.)
     * 2. Converts to UTF-8 if needed
     * 3. Removes BOM (Byte Order Mark)
     * 4. Filters out non-printable control characters
     * 
     * @param string $str Input string to be converted to UTF-8
     * @param bool $strict Strict mode. If true, throws exception on conversion failure. Default is false.
     * @return string UTF-8 encoded string, guaranteed to be valid UTF-8
     * @throws \Exception If strict mode is enabled and encoding conversion fails
     */
    public static function ensureUtf8(string $str, bool $strict = false): string
    {
        if (empty($str)) {
            return '';
        }
        
        // Check if already valid UTF-8
        if (mb_check_encoding($str, 'UTF-8')) {
            // Even if valid UTF-8, ensure no BOM or special issues
            return self::cleanUtf8String($str);
        }
        
        // Try to detect encoding
        $encoding = mb_detect_encoding(
            $str, 
            self::$supportedEncodings, 
            true // Strict mode
        );
        
        if ($encoding && $encoding !== 'UTF-8') {
            Logger::info('Non-UTF-8 Encoding Detected', [
                'original_encoding' => $encoding,
                'string_length' => strlen($str),
                'string_preview' => mb_substr($str, 0, 50)
            ]);
            
            // Convert to UTF-8
            $converted = mb_convert_encoding($str, 'UTF-8', $encoding);
            
            if ($converted === false && $strict) {
                throw new \Exception(
                    sprintf(
                        /* translators: %s: source encoding name */
                        esc_html__('Failed to convert string from %s to UTF-8', 'wpkj-fluentcart-alipay-payment'),
                        esc_html($encoding)
                    )
                );
            }
            
            return $converted !== false ? self::cleanUtf8String($converted) : $str;
        }
        
        // Fallback: force convert from UTF-8 to UTF-8 to clean up issues
        // This handles cases where encoding detection fails but string is UTF-8
        $cleaned = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        
        return $cleaned !== false ? self::cleanUtf8String($cleaned) : $str;
    }
    
    /**
     * Clean UTF-8 string by removing BOM and control characters
     * 
     * @param string $str Input string
     * @return string Cleaned string
     */
    private static function cleanUtf8String(string $str): string
    {
        // Remove BOM (Byte Order Mark)
        // UTF-8 BOM: EF BB BF
        $str = str_replace("\xEF\xBB\xBF", '', $str);
        
        // Remove non-printable control characters (except line breaks)
        // \x00-\x08: NULL to BACKSPACE
        // \x0B-\x0C: VERTICAL TAB, FORM FEED
        // \x0E-\x1F: SHIFT OUT to UNIT SEPARATOR
        // \x7F: DELETE
        $str = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $str);
        
        return $str;
    }
    
    /**
     * Ensure all string values in array are valid UTF-8
     * 
     * Recursively processes arrays to ensure all string values are UTF-8.
     * Non-string values are preserved as-is.
     * 
     * @param array $data Input array
     * @param bool $strict Strict mode
     * @return array UTF-8 encoded array
     */
    public static function ensureUtf8Array(array $data, bool $strict = false): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively process nested arrays
                $data[$key] = self::ensureUtf8Array($value, $strict);
            } elseif (is_string($value)) {
                // Convert string values
                $data[$key] = self::ensureUtf8($value, $strict);
            }
            // Other types (int, bool, null, etc.) remain unchanged
        }
        
        return $data;
    }
    
    /**
     * Validate UTF-8 encoding
     * 
     * @param string $str Input string
     * @return bool True if valid UTF-8
     */
    public static function isValidUtf8(string $str): bool
    {
        return mb_check_encoding($str, 'UTF-8');
    }
    
    /**
     * Detect string encoding
     * 
     * @param string $str Input string
     * @return string|false Detected encoding or false
     */
    public static function detectEncoding(string $str)
    {
        return mb_detect_encoding($str, self::$supportedEncodings, true);
    }
    
    /**
     * Sanitize string for Alipay API
     * 
     * Ensures string is UTF-8 and within Alipay's length limits.
     * This method is specifically designed for Alipay API parameters.
     * 
     * @param string $str Input string to sanitize
     * @param int $maxLength Maximum length in characters (not bytes). Defaults to MAX_SUBJECT_LENGTH (256).
     * @param bool $strict Strict mode. If true, throws exception on conversion failure. Default is false.
     * @return string Sanitized and truncated string, guaranteed to be valid UTF-8
     */
    public static function sanitizeForAlipay(
        string $str, 
        int $maxLength = AlipayConfig::MAX_SUBJECT_LENGTH, 
        bool $strict = false
    ): string {
        // Ensure UTF-8
        $str = self::ensureUtf8($str, $strict);
        
        // Trim to max length (use mb_substr for proper UTF-8 handling)
        if (mb_strlen($str, 'UTF-8') > $maxLength) {
            $str = mb_substr($str, 0, $maxLength, 'UTF-8');
            
            Logger::info('String Truncated for Alipay', [
                'original_length' => mb_strlen($str, 'UTF-8'),
                'max_length' => $maxLength,
                'truncated_preview' => mb_substr($str, 0, 50, 'UTF-8')
            ]);
        }
        
        return $str;
    }
    
    /**
     * Convert encoding
     * 
     * General-purpose encoding conversion with error handling
     * 
     * @param string $str Input string
     * @param string $toEncoding Target encoding
     * @param string $fromEncoding Source encoding (auto-detect if null)
     * @return string Converted string
     * @throws \Exception If conversion fails
     */
    public static function convertEncoding(
        string $str,
        string $toEncoding,
        ?string $fromEncoding = null
    ): string {
        if (empty($str)) {
            return '';
        }
        
        // Auto-detect source encoding if not specified
        if ($fromEncoding === null) {
            $fromEncoding = self::detectEncoding($str);
            if ($fromEncoding === false) {
                $fromEncoding = 'UTF-8'; // Fallback
            }
        }
        
        // No conversion needed
        if ($fromEncoding === $toEncoding) {
            return $str;
        }
        
        $converted = mb_convert_encoding($str, $toEncoding, $fromEncoding);
        
        if ($converted === false) {
            throw new \Exception(
                sprintf(
                    /* translators: 1: source encoding name, 2: target encoding name */
                    esc_html__('Failed to convert string from %1$s to %2$s', 'wpkj-fluentcart-alipay-payment'),
                    esc_html($fromEncoding),
                    esc_html($toEncoding)
                )
            );
        }
        
        return $converted;
    }
    
    /**
     * Get encoding information for debugging
     * 
     * @param string $str Input string
     * @return array Encoding information
     */
    public static function getEncodingInfo(string $str): array
    {
        return [
            'detected_encoding' => self::detectEncoding($str),
            'is_valid_utf8' => self::isValidUtf8($str),
            'length_bytes' => strlen($str),
            'length_chars' => mb_strlen($str, 'UTF-8'),
            'has_bom' => strpos($str, "\xEF\xBB\xBF") !== false,
            'preview' => mb_substr($str, 0, 100, 'UTF-8')
        ];
    }
}
