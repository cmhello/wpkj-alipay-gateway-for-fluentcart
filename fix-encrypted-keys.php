<?php
/**
 * Fix Encrypted Keys Script
 * 
 * This script clears corrupted encrypted keys from the database
 * that were caused by the double encryption bug.
 * 
 * USAGE: php fix-encrypted-keys.php
 */

// Load WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

echo "Alipay Settings Fix Script\n";
echo "==========================\n\n";

// Get current settings
$settings = get_option('fluent_cart_payment_settings_alipay', []);

if (empty($settings)) {
    echo "No Alipay settings found.\n";
    exit(0);
}

echo "Current settings found:\n";

// Check test keys
if (isset($settings['test_private_key'])) {
    $keyLength = strlen($settings['test_private_key']);
    $isEncrypted = str_starts_with($settings['test_private_key'], 'enc_');
    echo "- test_private_key: {$keyLength} chars, encrypted: " . ($isEncrypted ? 'YES' : 'NO') . "\n";
    
    // If encrypted, clear it
    if ($isEncrypted) {
        $settings['test_private_key'] = '';
        echo "  → CLEARED (will need to re-enter)\n";
    }
}

if (isset($settings['test_alipay_public_key'])) {
    echo "- test_alipay_public_key: " . strlen($settings['test_alipay_public_key']) . " chars\n";
}

// Check live keys
if (isset($settings['live_private_key'])) {
    $keyLength = strlen($settings['live_private_key']);
    $isEncrypted = str_starts_with($settings['live_private_key'], 'enc_');
    echo "- live_private_key: {$keyLength} chars, encrypted: " . ($isEncrypted ? 'YES' : 'NO') . "\n";
    
    // If encrypted, clear it
    if ($isEncrypted) {
        $settings['live_private_key'] = '';
        echo "  → CLEARED (will need to re-enter)\n";
    }
}

if (isset($settings['live_alipay_public_key'])) {
    echo "- live_alipay_public_key: " . strlen($settings['live_alipay_public_key']) . " chars\n";
}

echo "\n";

// Save cleaned settings
$result = update_option('fluent_cart_payment_settings_alipay', $settings);

if ($result) {
    echo "✓ Settings updated successfully!\n";
    echo "\nNEXT STEPS:\n";
    echo "1. Go to FluentCart → Settings → Payment Gateways → Alipay\n";
    echo "2. Re-enter your private keys\n";
    echo "3. Save the settings\n";
    echo "\nThe keys will now be encrypted properly (only once).\n";
} else {
    echo "✗ Failed to update settings (or no changes were needed)\n";
}

echo "\nDone!\n";
