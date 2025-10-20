<?php
/**
 * Encoding Test Script
 * 
 * This script tests UTF-8 encoding handling for product titles
 * containing punctuation marks (especially full-width punctuation)
 * 
 * Usage: Run from command line or browser
 */

// Test cases with various punctuation marks
$testCases = [
    'Basic English' => 'Product Name',
    'Full-width comma' => 'Product，Name',
    'Full-width period' => 'Product。Name',
    'Full-width parentheses' => 'Product（Test）',
    'Full-width colon' => 'Product：Test',
    'Mixed punctuation' => 'Product，Test。Name（2025）：Best',
    'Chinese with punctuation' => '测试产品，包含标点符号。（重要）',
    'Long title with truncation' => str_repeat('测试产品，', 30) . '结束',
];

echo "=== UTF-8 Encoding Test ===\n\n";

foreach ($testCases as $label => $title) {
    echo "Test Case: {$label}\n";
    echo "Original: {$title}\n";
    echo "Length: " . mb_strlen($title, 'UTF-8') . " chars\n";
    
    // Test 1: Direct truncation (new approach)
    $truncated = $title;
    if (mb_strlen($truncated, 'UTF-8') > 256) {
        $truncated = mb_substr($truncated, 0, 256, 'UTF-8');
    }
    
    // Test 2: JSON encode with JSON_UNESCAPED_UNICODE
    $jsonEncoded = json_encode([
        'subject' => $truncated,
        'body' => $truncated . ' x1'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    echo "Truncated: {$truncated}\n";
    echo "JSON: {$jsonEncoded}\n";
    echo "JSON Valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO') . "\n";
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON Error: " . json_last_error_msg() . "\n";
    }
    
    // Test 3: Check if UTF-8 is still valid
    echo "UTF-8 Valid: " . (mb_check_encoding($truncated, 'UTF-8') ? 'YES' : 'NO') . "\n";
    
    echo "\n";
}

echo "=== Test Complete ===\n";
