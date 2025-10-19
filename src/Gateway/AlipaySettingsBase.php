<?php

namespace WPKJFluentCart\Alipay\Gateway;

use FluentCart\App\Helpers\Helper as FluentCartHelper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\StoreSettings;

/**
 * Alipay Settings Handler
 * 
 * Manages gateway configuration and credentials storage
 */
class AlipaySettingsBase extends BaseGatewaySettings
{
    /**
     * Meta key for settings storage in database
     * 
     * @var string
     */
    public $methodHandler = 'fluent_cart_payment_settings_alipay';

    /**
     * Settings array
     * 
     * @var array
     */
    public $settings;

    /**
     * Store settings instance
     * 
     * @var StoreSettings|null
     */
    public $storeSettings = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }
        
        // Check if keys are defined in wp-config.php
        $isTestDefined = $this->hasManualTestKeys();
        $isLiveDefined = $this->hasManualLiveKeys();

        if ($isTestDefined || $isLiveDefined) {
            $settings['define_test_keys'] = $isTestDefined;
            $settings['define_live_keys'] = $isLiveDefined;
        }

        $this->settings = $settings;

        if (!$this->storeSettings) {
            $this->storeSettings = new StoreSettings();
        }
    }

    /**
     * Check if manual test keys are defined
     * 
     * @return bool
     */
    private function hasManualTestKeys(): bool
    {
        return defined('WPKJ_ALIPAY_TEST_APP_ID') 
            && defined('WPKJ_ALIPAY_TEST_PRIVATE_KEY') 
            && defined('WPKJ_ALIPAY_TEST_PUBLIC_KEY');
    }

    /**
     * Check if manual live keys are defined
     * 
     * @return bool
     */
    private function hasManualLiveKeys(): bool
    {
        return defined('WPKJ_ALIPAY_LIVE_APP_ID') 
            && defined('WPKJ_ALIPAY_LIVE_PRIVATE_KEY') 
            && defined('WPKJ_ALIPAY_LIVE_PUBLIC_KEY');
    }

    /**
     * Get default settings
     * 
     * @return array
     */
    public static function getDefaults(): array
    {
        return [
            'is_active' => 'no',
            'define_test_keys' => false,
            'define_live_keys' => false,
            'payment_mode' => 'test',
            'gateway_description' => '',
            'test_app_id' => '',
            'test_private_key' => '',
            'test_alipay_public_key' => '',
            'live_app_id' => '',
            'live_private_key' => '',
            'live_alipay_public_key' => '',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'notify_url_verification' => 'yes',
        ];
    }

    /**
     * Check if gateway is active
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        $settings = $this->get();

        if ($settings['is_active'] !== 'yes') {
            return false;
        }

        // Check if credentials are available
        $mode = $this->getMode();
        
        if ($mode === 'test') {
            return !empty($this->getAppId('test'));
        }
        
        return !empty($this->getAppId('live'));
    }

    /**
     * Get setting value
     * 
     * @param string $key Setting key
     * @return mixed
     */
    public function get($key = '')
    {
        $settings = $this->settings;
        if ($key) {
            return $this->settings[$key] ?? null;
        }
        return $settings;
    }

    /**
     * Get payment mode (test or live)
     * 
     * @return string
     */
    public function getMode()
    {
        if (!$this->storeSettings) {
            $this->storeSettings = new StoreSettings();
        }
        return $this->storeSettings->get('order_mode');
    }

    /**
     * Get App ID based on mode
     * 
     * @param string $mode Payment mode (test/live)
     * @return string
     */
    public function getAppId($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return defined('WPKJ_ALIPAY_TEST_APP_ID') 
                ? WPKJ_ALIPAY_TEST_APP_ID 
                : $this->get()['test_app_id'];
        }

        return defined('WPKJ_ALIPAY_LIVE_APP_ID') 
            ? WPKJ_ALIPAY_LIVE_APP_ID 
            : $this->get()['live_app_id'];
    }

    /**
     * Get private key based on mode
     * 
     * @param string $mode Payment mode (test/live)
     * @return string
     */
    public function getPrivateKey($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            if (defined('WPKJ_ALIPAY_TEST_PRIVATE_KEY')) {
                return WPKJ_ALIPAY_TEST_PRIVATE_KEY;
            }
            
            $encryptedKey = $this->get()['test_private_key'] ?? '';
            if (empty($encryptedKey)) {
                throw new \Exception(__('Test private key is not configured', 'wpkj-fluentcart-alipay-payment'));
            }
            
            $decrypted = FluentCartHelper::decryptKey($encryptedKey);
            if ($decrypted === false || empty($decrypted)) {
                \WPKJFluentCart\Alipay\Utils\Logger::error('Private Key Decryption Failed', [
                    'mode' => 'test',
                    'encrypted_key_length' => strlen($encryptedKey)
                ]);
                throw new \Exception(__('Unable to decrypt test private key. Please re-enter your credentials.', 'wpkj-fluentcart-alipay-payment'));
            }
            
            return $decrypted;
        }

        if (defined('WPKJ_ALIPAY_LIVE_PRIVATE_KEY')) {
            return WPKJ_ALIPAY_LIVE_PRIVATE_KEY;
        }
        
        $encryptedKey = $this->get()['live_private_key'] ?? '';
        if (empty($encryptedKey)) {
            throw new \Exception(__('Live private key is not configured', 'wpkj-fluentcart-alipay-payment'));
        }
        
        $decrypted = FluentCartHelper::decryptKey($encryptedKey);
        if ($decrypted === false || empty($decrypted)) {
            \WPKJFluentCart\Alipay\Utils\Logger::error('Private Key Decryption Failed', [
                'mode' => 'live',
                'encrypted_key_length' => strlen($encryptedKey)
            ]);
            throw new \Exception(__('Unable to decrypt live private key. Please re-enter your credentials.', 'wpkj-fluentcart-alipay-payment'));
        }
        
        return $decrypted;
    }

    /**
     * Get Alipay public key based on mode
     * 
     * @param string $mode Payment mode (test/live)
     * @return string
     */
    public function getAlipayPublicKey($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return defined('WPKJ_ALIPAY_TEST_PUBLIC_KEY') 
                ? WPKJ_ALIPAY_TEST_PUBLIC_KEY 
                : $this->get()['test_alipay_public_key'];
        }

        return defined('WPKJ_ALIPAY_LIVE_PUBLIC_KEY') 
            ? WPKJ_ALIPAY_LIVE_PUBLIC_KEY 
            : $this->get()['live_alipay_public_key'];
    }

    /**
     * Get charset
     * 
     * @return string
     */
    public function getCharset()
    {
        return $this->get('charset') ?: 'UTF-8';
    }

    /**
     * Get sign type
     * 
     * @return string
     */
    public function getSignType()
    {
        return $this->get('sign_type') ?: 'RSA2';
    }
}
