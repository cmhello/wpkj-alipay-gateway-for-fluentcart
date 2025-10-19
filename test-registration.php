<?php
/**
 * Test gateway registration
 */

require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

echo "=== Gateway Registration Test ===\n\n";

// Trigger init hook
do_action('init');

$manager = FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::getInstance();
$gateways = $manager->all();

echo "Registered gateways:\n";
foreach (array_keys($gateways) as $name) {
    echo "  - $name\n";
}

if (isset($gateways['alipay'])) {
    echo "\n✓ Alipay is registered!\n";
    
    $alipay = $gateways['alipay'];
    echo "  Gateway class: " . get_class($alipay) . "\n";
    
    if (method_exists($alipay, 'meta')) {
        $meta = $alipay->meta();
        echo "  Title: " . $meta['title'] . "\n";
        echo "  Route: " . $meta['route'] . "\n";
    }
} else {
    echo "\n✗ Alipay NOT registered!\n";
}

echo "\n=== End Test ===\n";
