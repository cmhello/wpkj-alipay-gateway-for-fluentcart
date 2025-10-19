<?php

namespace WPKJFluentCart\Alipay\Utils;

/**
 * Logger Utility
 * 
 * Provides logging functionality using FluentCart's logging system
 */
class Logger
{
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
