<?php
/**
 * Quick settings check script
 */

require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

echo "=== Alipay Settings Check ===\n\n";

// FluentCart uses its own Meta table, not wp_options!
if (function_exists('fluent_cart_get_option')) {
    $settings = fluent_cart_get_option('fluent_cart_payment_settings_alipay', []);
} else {
    echo "❌ fluent_cart_get_option() not available!\n";
    exit(1);
}

if (empty($settings)) {
    echo "❌ No settings found in FluentCart Meta table!\n";
    
    // Try direct query
    if (class_exists('\\FluentCart\\App\\Models\\Meta')) {
        $meta = \FluentCart\App\Models\Meta::query()
            ->where('meta_key', 'fluent_cart_payment_settings_alipay')
            ->where('object_type', 'option')
            ->first();
        
        if ($meta) {
            echo "✓ Found in Meta table directly\n";
            $settings = $meta->meta_value;
        } else {
            echo "❌ No record in Meta table\n";
            exit(1);
        }
    } else {
        exit(1);
    }
}

echo "1. Basic Info:\n";
echo "   is_active: " . ($settings['is_active'] ?? 'not set') . "\n";
echo "   payment_mode: " . ($settings['payment_mode'] ?? 'not set') . "\n\n";

echo "2. Live Credentials (Encrypted):\n";
if (isset($settings['live_app_id'])) {
    echo "   live_app_id: " . $settings['live_app_id'] . "\n";
}
if (isset($settings['live_private_key'])) {
    echo "   live_private_key length: " . strlen($settings['live_private_key']) . "\n";
    echo "   Sample: " . substr($settings['live_private_key'], 0, 60) . "...\n";
}
if (isset($settings['live_alipay_public_key'])) {
    echo "   live_alipay_public_key length: " . strlen($settings['live_alipay_public_key']) . "\n";
}

echo "\n3. Decryption Test:\n";
if (isset($settings['live_private_key'])) {
    $decrypted = FluentCart\App\Helpers\Helper::decryptKey($settings['live_private_key']);
    
    if ($decrypted === false) {
        echo "   ❌ Decryption FAILED\n";
    } else if ($decrypted === $settings['live_private_key']) {
        echo "   ⚠️  Not encrypted (returned same value)\n";
    } else {
        echo "   ✓ Decryption SUCCESS\n";
        echo "   Decrypted length: " . strlen($decrypted) . "\n";
        echo "   First chars: " . substr($decrypted, 0, 50) . "...\n";
        
        if (strpos($decrypted, 'MII') === 0) {
            echo "   ✓ Valid RSA key format!\n";
        }
    }
}

echo "\n4. Using AlipaySettingsBase:\n";
try {
    $settingsBase = new WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase();
    
    $appId = $settingsBase->getAppId('live');
    echo "   App ID: " . $appId . "\n";
    
    $privateKey = $settingsBase->getPrivateKey('live');
    if ($privateKey) {
        echo "   Private Key length: " . strlen($privateKey) . "\n";
        if (strpos($privateKey, 'MII') === 0) {
            echo "   ✓ Valid format!\n";
        } else {
            echo "   ❌ Invalid format\n";
        }
    } else {
        echo "   ❌ Private key is empty/false\n";
    }
    
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Check Complete ===\n";
