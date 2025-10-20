<?php

namespace WPKJFluentCart\Alipay\API;

use WPKJFluentCart\Alipay\Config\AlipayConfig;
use WPKJFluentCart\Alipay\Gateway\AlipaySettingsBase;
use WPKJFluentCart\Alipay\Services\EncodingService;
use WPKJFluentCart\Alipay\Utils\Helper;
use WPKJFluentCart\Alipay\Utils\Logger;
use WPKJFluentCart\Alipay\Detector\ClientDetector;

/**
 * Alipay API Handler
 * 
 * Handles all communication with Alipay payment gateway
 */
class AlipayAPI
{
    /**
     * Settings instance
     * 
     * @var AlipaySettingsBase
     */
    private $settings;

    /**
     * Gateway configuration
     * 
     * @var array
     */
    private $config;

    /**
     * Alipay gateway URLs
     */
    const GATEWAY_URL_PROD = AlipayConfig::GATEWAY_URL_PROD;
    const GATEWAY_URL_SANDBOX = AlipayConfig::GATEWAY_URL_SANDBOX;

    /**
     * Constructor
     * 
     * @param AlipaySettingsBase $settings Settings instance
     */
    public function __construct(AlipaySettingsBase $settings)
    {
        $this->settings = $settings;
        $this->config = $this->buildConfig();
    }

    /**
     * Build configuration array
     * 
     * @return array
     */
    private function buildConfig()
    {
        $mode = $this->settings->getMode();
        
        return [
            'app_id' => $this->settings->getAppId($mode),
            'private_key' => $this->settings->getPrivateKey($mode),
            'alipay_public_key' => $this->settings->getAlipayPublicKey($mode),
            'charset' => $this->settings->getCharset(),
            'sign_type' => $this->settings->getSignType(),
            'gateway_url' => $mode === 'test' ? self::GATEWAY_URL_SANDBOX : self::GATEWAY_URL_PROD,
        ];
    }

    /**
     * Create payment request
     * 
     * @param array $orderData Order data
     * @return array|\WP_Error Payment form data or error
     */
    public function createPayment($orderData)
    {
        try {
            $method = ClientDetector::getPaymentMethod($this->settings);
            
            $bizContent = [
                'out_trade_no' => $orderData['out_trade_no'],
                'total_amount' => $orderData['total_amount'],
                'subject' => $orderData['subject'],
                'body' => $orderData['body'] ?? '',
                'product_code' => $this->getProductCode($method),
            ];

            if (!empty($orderData['timeout_express'])) {
                $bizContent['timeout_express'] = $orderData['timeout_express'];
            }

            $params = $this->buildRequestParams(
                $bizContent, 
                $method,
                $orderData['return_url'],
                $orderData['notify_url']
            );

            $paymentUrl = $this->generatePaymentUrl($params);

            Logger::info('Payment Created', [
                'out_trade_no' => $orderData['out_trade_no'],
                'amount' => $orderData['total_amount'],
                'method' => $method
            ]);

            return [
                'payment_url' => $paymentUrl,
                'method' => $method,
                'out_trade_no' => $orderData['out_trade_no']
            ];

        } catch (\Exception $e) {
            Logger::error('Create Payment Error', $e->getMessage());
            return new \WP_Error('alipay_create_error', $e->getMessage());
        }
    }

    /**
     * Create Face-to-Face payment (QR code)
     * 
     * @param array $orderData Order data
     * @return array|\WP_Error QR code data or error
     */
    public function createFaceToFacePayment($orderData)
    {
        try {
            $bizContent = [
                'out_trade_no' => $orderData['out_trade_no'],
                'total_amount' => $orderData['total_amount'],
                'subject' => $orderData['subject'],
            ];

            if (!empty($orderData['body'])) {
                $bizContent['body'] = $orderData['body'];
            }

            if (!empty($orderData['timeout_express'])) {
                $bizContent['timeout_express'] = $orderData['timeout_express'];
            }
            
            // Log the biz_content before encoding to debug Chinese character issues
            Logger::info('Face-to-Face Payment BizContent', [
                'subject' => $bizContent['subject'],
                'subject_encoding' => mb_detect_encoding($bizContent['subject']),
                'subject_is_utf8' => mb_check_encoding($bizContent['subject'], 'UTF-8') ? 'YES' : 'NO',
                'body' => $bizContent['body'] ?? 'N/A'
            ]);

            $params = $this->buildRequestParams(
                $bizContent,
                'alipay.trade.precreate',
                '',
                $orderData['notify_url']
            );

            Logger::info('Sending Face-to-Face Payment Request', [
                'gateway_url' => $this->config['gateway_url'],
                'out_trade_no' => $orderData['out_trade_no'],
                'mode' => $this->settings->getMode()
            ]);

            $response = wp_remote_post($this->config['gateway_url'], [
                'body' => $params,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                Logger::error('WP_Error in Face-to-Face Request', [
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message()
                ]);
                return $response;
            }

            $httpCode = wp_remote_retrieve_response_code($response);
            if ($httpCode !== 200) {
                $responseBody = wp_remote_retrieve_body($response);
                Logger::error('HTTP Request Failed', [
                    'http_code' => $httpCode,
                    'method' => 'precreate',
                    'out_trade_no' => $orderData['out_trade_no'],
                    'gateway_url' => $this->config['gateway_url'],
                    'response_body' => substr($responseBody, 0, 500)
                ]);
                return new \WP_Error(
                    'alipay_http_error',
                    sprintf(__('HTTP %d error from Alipay', 'wpkj-fluentcart-alipay-payment'), $httpCode)
                );
            }

            $body = wp_remote_retrieve_body($response);
            
            if (empty($body)) {
                return new \WP_Error('alipay_precreate_error', __('Empty response from Alipay', 'wpkj-fluentcart-alipay-payment'));
            }

            $result = json_decode($body, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                Logger::error('JSON Decode Error', [
                    'error' => json_last_error_msg(),
                    'error_code' => $jsonError,
                    'body_preview' => substr($body, 0, 500)
                ]);
                return new \WP_Error(
                    'alipay_precreate_error',
                    __('Invalid JSON response from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            $responseKey = 'alipay_trade_precreate_response';
            if (!isset($result[$responseKey])) {
                Logger::error('Invalid Precreate Response Structure', [
                    'out_trade_no' => $orderData['out_trade_no'],
                    'response_keys' => array_keys($result),
                    'response_body' => substr($body, 0, 1000)
                ]);
                return new \WP_Error(
                    'alipay_precreate_error',
                    __('Invalid response structure from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            $precreateResponse = $result[$responseKey];

            if (!isset($precreateResponse['code']) || $precreateResponse['code'] !== '10000') {
                $errorMsg = $precreateResponse['sub_msg'] ?? $precreateResponse['msg'] ?? __('Face-to-Face payment creation failed', 'wpkj-fluentcart-alipay-payment');
                
                Logger::error('Precreate Failed', [
                    'out_trade_no' => $orderData['out_trade_no'],
                    'code' => $precreateResponse['code'] ?? 'unknown',
                    'message' => $errorMsg,
                    'sub_code' => $precreateResponse['sub_code'] ?? ''
                ]);
                
                return new \WP_Error('alipay_precreate_error', $errorMsg);
            }

            if (empty($precreateResponse['qr_code'])) {
                Logger::error('Missing QR Code in Response', [
                    'out_trade_no' => $orderData['out_trade_no']
                ]);
                return new \WP_Error(
                    'alipay_precreate_error',
                    __('QR code not found in response', 'wpkj-fluentcart-alipay-payment')
                );
            }

            Logger::info('Face-to-Face Payment Created', [
                'out_trade_no' => $orderData['out_trade_no'],
                'amount' => $orderData['total_amount']
            ]);

            return [
                'qr_code' => $precreateResponse['qr_code'],
                'out_trade_no' => $orderData['out_trade_no'],
                'method' => 'alipay.trade.precreate'
            ];

        } catch (\Exception $e) {
            Logger::error('Create Face-to-Face Payment Error', $e->getMessage());
            return new \WP_Error('alipay_precreate_error', $e->getMessage());
        }
    }

    /**
     * Get product code based on payment method
     * 
     * @param string $method Payment method
     * @return string Product code
     */
    private function getProductCode($method)
    {
        $productCodes = [
            'alipay.trade.page.pay' => 'FAST_INSTANT_TRADE_PAY',
            'alipay.trade.wap.pay' => 'QUICK_WAP_WAY',
            'alipay.trade.app.pay' => 'QUICK_MSECURITY_PAY',
        ];

        return $productCodes[$method] ?? 'FAST_INSTANT_TRADE_PAY';
    }

    /**
     * Build request parameters
     * 
     * @param array $bizContent Business content
     * @param string $method API method
     * @param string $returnUrl Return URL (optional)
     * @param string $notifyUrl Notify URL (optional)
     * @return array Request parameters
     */
    private function buildRequestParams($bizContent, $method, $returnUrl = '', $notifyUrl = '')
    {
        // Do NOT use EncodingService::ensureUtf8Array() here!
        // Avoid any encoding conversion that may corrupt UTF-8 multibyte characters
        // Rely solely on json_encode with JSON_UNESCAPED_UNICODE flag
        
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => $method,
            'charset' => $this->config['charset'],
            'sign_type' => $this->config['sign_type'],
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            // JSON_UNESCAPED_UNICODE ensures Chinese characters are not escaped
            // JSON_UNESCAPED_SLASHES prevents escaping of forward slashes
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        
        // Add return_url and notify_url if provided (they must be included in signature)
        if (!empty($returnUrl)) {
            $params['return_url'] = $returnUrl;
        }
        if (!empty($notifyUrl)) {
            $params['notify_url'] = $notifyUrl;
        }

        // Generate signature (after all params are added)
        $params['sign'] = $this->generateSign($params);

        return $params;
    }

    /**
     * Generate signature for request
     * 
     * @param array $params Request parameters
     * @return string Signature
     */
    private function generateSign($params)
    {
        // Remove sign parameter if exists
        unset($params['sign']);

        // Sort parameters
        ksort($params);

        // Build sign string
        $signString = '';
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && $key !== 'sign') {
                $signString .= $key . '=' . $value . '&';
            }
        }
        $signString = rtrim($signString, '&');

        // Get private key (already decrypted by AlipaySettingsBase)
        $privateKeyContent = $this->config['private_key'];
        
        // Format private key with proper headers
        $privateKey = Helper::formatPrivateKey($privateKeyContent);
        
        // Log for debugging (only in test mode)
        if ($this->config['gateway_url'] === self::GATEWAY_URL_SANDBOX) {
            Logger::info('Signature Generation Debug', [
                'sign_string' => $signString,
                'private_key_length' => strlen($privateKeyContent),
                'private_key_first_10' => substr($privateKeyContent, 0, 10),
                'formatted_key_length' => strlen($privateKey)
            ]);
        }

        // Generate signature
        $signature = '';
        $signType = $this->config['sign_type'];

        if ($signType === 'RSA2') {
            $result = openssl_sign($signString, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        } else {
            $result = openssl_sign($signString, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        }
        
        if ($result === false) {
            $opensslError = openssl_error_string();
            Logger::error('OpenSSL Sign Failed', [
                'openssl_error' => $opensslError,
                'sign_type' => $signType
            ]);
            throw new \Exception('Failed to generate signature: ' . $opensslError);
        }

        return base64_encode($signature);
    }

    /**
     * Generate payment URL
     * 
     * @param array $params Request parameters
     * @return string Payment URL
     */
    private function generatePaymentUrl($params)
    {
        // Use PHP_QUERY_RFC3986 to prevent HTML encoding
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $paymentUrl = $this->config['gateway_url'] . '?' . $queryString;
        
        // Log for debugging (only in test mode)
        if ($this->config['gateway_url'] === self::GATEWAY_URL_SANDBOX) {
            Logger::info('Payment URL Generated', [
                'url_length' => strlen($paymentUrl),
                'has_html_encoded_amp' => strpos($queryString, '&amp;') !== false ? 'YES (ERROR!)' : 'NO',
                'has_html_encoded_quot' => strpos($queryString, '&quot;') !== false ? 'YES (ERROR!)' : 'NO',
                'notify_url' => $params['notify_url'] ?? 'N/A',
                'return_url' => $params['return_url'] ?? 'N/A'
            ]);
        }
        
        return $paymentUrl;
    }

    /**
     * Verify signature from Alipay notification
     * 
     * @param array $data Notification data
     * @return bool Verification result
     */
    public function verifySignature($data)
    {
        if (!isset($data['sign']) || !isset($data['sign_type'])) {
            Logger::error('Signature Verification Failed', 'Missing sign or sign_type');
            return false;
        }

        $sign = $data['sign'];
        $signType = $data['sign_type'];

        // Remove sign and sign_type from data
        unset($data['sign']);
        unset($data['sign_type']);

        // Sort parameters
        ksort($data);

        // Build sign string
        $signString = '';
        foreach ($data as $key => $value) {
            if ($value !== '' && $value !== null) {
                $signString .= $key . '=' . $value . '&';
            }
        }
        $signString = rtrim($signString, '&');

        // Get Alipay public key
        $publicKey = Helper::formatPublicKey($this->config['alipay_public_key']);

        // Verify signature
        $signature = base64_decode($sign);
        
        if ($signType === 'RSA2') {
            $result = openssl_verify($signString, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        } else {
            $result = openssl_verify($signString, $signature, $publicKey, OPENSSL_ALGO_SHA1);
        }

        if ($result === 1) {
            Logger::info('Signature Verified', 'Signature verification successful');
            return true;
        }

        Logger::error('Signature Verification Failed', [
            'result' => $result,
            'sign_string' => $signString
        ]);

        return false;
    }

    /**
     * Query payment status
     * 
     * @param string $outTradeNo Order number
     * @return array|\WP_Error Payment status or error
     */
    public function queryPayment($outTradeNo)
    {
        try {
            $bizContent = [
                'out_trade_no' => $outTradeNo,
            ];

            $params = $this->buildRequestParams($bizContent, 'alipay.trade.query');

            $response = wp_remote_post($this->config['gateway_url'], [
                'body' => $params,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            // Check HTTP status code
            $httpCode = wp_remote_retrieve_response_code($response);
            if ($httpCode !== 200) {
                Logger::error('HTTP Request Failed', [
                    'http_code' => $httpCode,
                    'method' => 'queryPayment',
                    'out_trade_no' => $outTradeNo
                ]);
                return new \WP_Error(
                    'alipay_http_error',
                    sprintf(__('HTTP %d error from Alipay', 'wpkj-fluentcart-alipay-payment'), $httpCode)
                );
            }

            $body = wp_remote_retrieve_body($response);
            
            // Validate response body
            if (empty($body)) {
                return new \WP_Error('alipay_query_error', __('Empty response from Alipay', 'wpkj-fluentcart-alipay-payment'));
            }

            $result = json_decode($body, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                Logger::error('JSON Decode Error', [
                    'error' => json_last_error_msg(),
                    'error_code' => $jsonError,
                    'body_preview' => substr($body, 0, 500)
                ]);
                return new \WP_Error(
                    'alipay_query_error',
                    __('Invalid JSON response from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            if (!is_array($result)) {
                return new \WP_Error(
                    'alipay_query_error',
                    __('Unexpected response format from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            return $result;

        } catch (\Exception $e) {
            Logger::error('Query Payment Error', $e->getMessage());
            return new \WP_Error('alipay_query_error', $e->getMessage());
        }
    }

    /**
     * Process refund
     * 
     * Supports both out_trade_no and trade_no for identifying the transaction to refund.
     * Priority: trade_no (Alipay's transaction ID) > out_trade_no (merchant's order ID)
     * 
     * @param array $refundData Refund data (must contain either 'trade_no' or 'out_trade_no')
     * @return array|\WP_Error Refund result or error
     */
    public function refund($refundData)
    {
        try {
            $bizContent = [
                'refund_amount' => $refundData['refund_amount'],
                'out_request_no' => $refundData['out_request_no'],
            ];
            
            // Prefer trade_no over out_trade_no (trade_no is more reliable)
            if (!empty($refundData['trade_no'])) {
                $bizContent['trade_no'] = $refundData['trade_no'];
            } elseif (!empty($refundData['out_trade_no'])) {
                $bizContent['out_trade_no'] = $refundData['out_trade_no'];
            } else {
                throw new \Exception('Refund requires either trade_no or out_trade_no');
            }

            if (!empty($refundData['refund_reason'])) {
                $bizContent['refund_reason'] = $refundData['refund_reason'];
            }

            $params = $this->buildRequestParams($bizContent, 'alipay.trade.refund');

            $response = wp_remote_post($this->config['gateway_url'], [
                'body' => $params,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            // Check HTTP status code
            $httpCode = wp_remote_retrieve_response_code($response);
            if ($httpCode !== 200) {
                Logger::error('HTTP Request Failed', [
                    'http_code' => $httpCode,
                    'method' => 'refund',
                    'identifier' => $refundData['trade_no'] ?? $refundData['out_trade_no'] ?? 'unknown'
                ]);
                return new \WP_Error(
                    'alipay_http_error',
                    sprintf(__('HTTP %d error from Alipay', 'wpkj-fluentcart-alipay-payment'), $httpCode)
                );
            }

            $body = wp_remote_retrieve_body($response);
            
            // Validate response body
            if (empty($body)) {
                return new \WP_Error('alipay_refund_error', __('Empty response from Alipay', 'wpkj-fluentcart-alipay-payment'));
            }

            $result = json_decode($body, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                Logger::error('JSON Decode Error', [
                    'error' => json_last_error_msg(),
                    'error_code' => $jsonError,
                    'body_preview' => substr($body, 0, 500)
                ]);
                return new \WP_Error(
                    'alipay_refund_error',
                    __('Invalid JSON response from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            if (!is_array($result)) {
                return new \WP_Error(
                    'alipay_refund_error',
                    __('Unexpected response format from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            Logger::info('Refund Processed', [
                'identifier' => $refundData['trade_no'] ?? $refundData['out_trade_no'] ?? 'unknown',
                'refund_amount' => $refundData['refund_amount']
            ]);

            return $result;

        } catch (\Exception $e) {
            Logger::error('Refund Error', $e->getMessage());
            return new \WP_Error('alipay_refund_error', $e->getMessage());
        }
    }

    /**
     * Query trade status
     * 
     * @param string $outTradeNo Out trade number
     * @return array|\WP_Error Trade data or error
     */
    public function queryTrade($outTradeNo)
    {
        try {
            // Check cache first (short TTL to reduce API calls)
            $cacheKey = 'alipay_query_' . md5($outTradeNo);
            $cached = get_transient($cacheKey);
            
            if ($cached !== false) {
                Logger::info('Query Trade Cache Hit', ['out_trade_no' => $outTradeNo]);
                return $cached;
            }
            
            $bizContent = [
                'out_trade_no' => $outTradeNo,
            ];

            $params = $this->buildRequestParams($bizContent, 'alipay.trade.query');

            $response = wp_remote_post($this->config['gateway_url'], [
                'body' => $params,
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            // Check HTTP status code
            $httpCode = wp_remote_retrieve_response_code($response);
            if ($httpCode !== 200) {
                Logger::error('HTTP Request Failed', [
                    'http_code' => $httpCode,
                    'method' => 'query',
                    'out_trade_no' => $outTradeNo
                ]);
                return new \WP_Error(
                    'alipay_http_error',
                    sprintf(__('HTTP %d error from Alipay', 'wpkj-fluentcart-alipay-payment'), $httpCode)
                );
            }

            $body = wp_remote_retrieve_body($response);
            
            if (empty($body)) {
                return new \WP_Error('alipay_query_error', __('Empty response from Alipay', 'wpkj-fluentcart-alipay-payment'));
            }

            // Ensure UTF-8 encoding to prevent JSON decode errors
            if (!mb_check_encoding($body, 'UTF-8')) {
                $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
            }

            $result = json_decode($body, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                Logger::error('JSON Decode Error', [
                    'error' => json_last_error_msg(),
                    'error_code' => $jsonError,
                    'body_preview' => substr($body, 0, 200)
                ]);
                return new \WP_Error(
                    'alipay_query_error',
                    __('Invalid JSON response from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            // Extract response data
            $responseKey = 'alipay_trade_query_response';
            if (!isset($result[$responseKey])) {
                Logger::error('Missing Response Key', [
                    'out_trade_no' => $outTradeNo,
                    'keys' => array_keys($result)
                ]);
                return new \WP_Error(
                    'alipay_query_error',
                    __('Invalid response structure from Alipay', 'wpkj-fluentcart-alipay-payment')
                );
            }

            $tradeData = $result[$responseKey];

            // Check for errors
            if (isset($tradeData['code']) && $tradeData['code'] !== '10000') {
                $errorMsg = $tradeData['sub_msg'] ?? $tradeData['msg'] ?? 'Unknown error';
                
                // Trade not found is not an error, just means payment not completed yet
                if ($tradeData['code'] === 'ACQ.TRADE_NOT_EXIST') {
                    Logger::info('Trade Not Exist (Payment Pending)', [
                        'out_trade_no' => $outTradeNo
                    ]);
                    $result = [
                        'trade_status' => 'WAIT_BUYER_PAY',
                        'msg' => 'Trade not found, payment may still be pending'
                    ];
                    // Cache pending status for shorter time
                    set_transient($cacheKey, $result, 2);
                    return $result;
                }
                
                Logger::error('Query Trade Failed', [
                    'out_trade_no' => $outTradeNo,
                    'code' => $tradeData['code'],
                    'message' => $errorMsg
                ]);
                
                return new \WP_Error('alipay_query_error', $errorMsg);
            }

            Logger::info('Query Trade Success', [
                'out_trade_no' => $outTradeNo,
                'trade_status' => $tradeData['trade_status'] ?? 'unknown'
            ]);

            // Cache successful result (use config TTL)
            if (isset($tradeData['trade_status'])) {
                set_transient($cacheKey, $tradeData, AlipayConfig::QUERY_CACHE_TTL);
            }

            return $tradeData;

        } catch (\Exception $e) {
            Logger::error('Query Trade Error', $e->getMessage());
            return new \WP_Error('alipay_query_error', $e->getMessage());
        }
    }
}
