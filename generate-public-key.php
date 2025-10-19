<?php
/**
 * Generate Application Public Key from Private Key
 * 
 * This tool extracts the public key from your configured private key
 * Upload this public key to Alipay Sandbox
 */

require_once('/www/wwwroot/waas.wpdaxue.com/wp-load.php');

if (!defined('WP_DEBUG') || !WP_DEBUG) {
    die('WP_DEBUG must be enabled');
}

use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Utils\Helper;

$settings = new AlipaySettingsBase();
$mode = $settings->getMode();

echo "=== Application Public Key Generator ===\n\n";
echo "Current Mode: {$mode}\n";
echo "App ID: " . $settings->getAppId($mode) . "\n\n";

try {
    // Get private key
    $privateKey = $settings->getPrivateKey($mode);
    $formattedPrivateKey = Helper::formatPrivateKey($privateKey);
    
    // Extract public key from private key
    $privateKeyResource = openssl_pkey_get_private($formattedPrivateKey);
    
    if ($privateKeyResource === false) {
        throw new Exception('Failed to load private key: ' . openssl_error_string());
    }
    
    $publicKeyDetails = openssl_pkey_get_details($privateKeyResource);
    
    if ($publicKeyDetails === false) {
        throw new Exception('Failed to extract public key: ' . openssl_error_string());
    }
    
    $publicKeyPem = $publicKeyDetails['key'];
    
    // Remove headers and format for Alipay
    $publicKeyContent = str_replace([
        '-----BEGIN PUBLIC KEY-----',
        '-----END PUBLIC KEY-----',
        "\r",
        "\n",
        ' '
    ], '', $publicKeyPem);
    
    echo "✓ Application Public Key Generated Successfully\n\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "COPY THIS PUBLIC KEY TO ALIPAY SANDBOX:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    echo $publicKeyContent . "\n\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    echo "Instructions:\n";
    echo "1. Login to https://open.alipay.com/develop/sandbox/app\n";
    echo "2. Find your application (App ID: " . $settings->getAppId($mode) . ")\n";
    echo "3. Click '设置应用公钥' (Set Application Public Key)\n";
    echo "4. Paste the above public key content (WITHOUT header/footer)\n";
    echo "5. Save and get the 'Alipay Public Key' from the page\n";
    echo "6. Update the 'Alipay Public Key' field in plugin settings\n\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "=== Complete ===\n";
