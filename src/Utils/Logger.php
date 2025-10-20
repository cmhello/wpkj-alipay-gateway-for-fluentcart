<?php

namespace WPKJFluentCart\Alipay\Utils;

/**
 * Logger Utility
 * 
 * Provides logging functionality using FluentCart's logging system
 * with environment-aware log level filtering
 */
class Logger
{
    /**
     * Check if logging is enabled for this level
     * 
     * In production mode, only log warnings and errors to reduce database load
     * 
     * @param string $level Log level (error, info, warning)
     * @return bool
     */
    private static function shouldLog(string $level): bool
    {
        // Always log errors and warnings
        if ($level === 'error' || $level === 'warning') {
            return true;
        }
        
        // For info logs, check environment
        if ($level === 'info') {
            // Check if in production mode
            if (class_exists('\FluentCart\Api\StoreSettings')) {
                try {
                    $storeSettings = new \FluentCart\Api\StoreSettings();
                    $isProduction = $storeSettings->get('order_mode') === 'live';
                    
                    // In production, only log info if debug mode is enabled
                    if ($isProduction) {
                        return defined('WP_DEBUG') && WP_DEBUG;
                    }
                } catch (\Exception $e) {
                    // If settings unavailable, default to logging
                }
            }
            
            // In test mode or if settings unavailable, log info
            return true;
        }
        
        return true;
    }
    
    /**
     * Log error message
     * 
     * @param string $title Log title
     * @param mixed $content Log content
     * @param array $context Additional context
     * @return void
     */
    public static function error($title, $content, $context = [])
    {
        self::log($title, $content, 'error', $context);
    }

    /**
     * Log info message
     * 
     * @param string $title Log title
     * @param mixed $content Log content
     * @param array $context Additional context
     * @return void
     */
    public static function info($title, $content, $context = [])
    {
        self::log($title, $content, 'info', $context);
    }

    /**
     * Log warning message
     * 
     * @param string $title Log title
     * @param mixed $content Log content
     * @param array $context Additional context
     * @return void
     */
    public static function warning($title, $content, $context = [])
    {
        self::log($title, $content, 'warning', $context);
    }

    /**
     * Generic log method
     * 
     * @param string $title Log title
     * @param mixed $content Log content
     * @param string $level Log level (error, info, warning)
     * @param array $context Additional context
     * @return void
     */
    private static function log($title, $content, $level = 'info', $context = [])
    {
        // Check if should log based on level and environment
        if (!self::shouldLog($level)) {
            return;
        }
        
        if (is_array($content) || is_object($content)) {
            $content = print_r($content, true);
        }

        // Use FluentCart's logging function
        if (function_exists('fluent_cart_add_log')) {
            fluent_cart_add_log(
                '[Alipay] ' . $title,
                $content,
                $level,
                array_merge(['log_type' => 'payment'], $context)
            );
        }

        // Fallback to error_log if FluentCart logging is unavailable
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Alipay %s] %s: %s', strtoupper($level), $title, $content));
        }
    }
}
