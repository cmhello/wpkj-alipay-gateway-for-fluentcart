<?php

namespace WPKJFluentCart\Alipay\API;

use FluentCart\App\Services\CustomPayment\PaymentIntent;
use FluentCart\App\Services\CustomPayment\PaymentItem;
use WPKJFluentCart\Alipay\Services\CustomPaymentService;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Custom Payment API Handler
 * 
 * Handles REST API requests for custom payment creation
 * 
 * @since 1.0.8
 */
class CustomPaymentAPI
{
    /**
     * Register REST API routes
     */
    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register REST API routes
     */
    public function registerRoutes()
    {
        // Create custom payment order
        register_rest_route('wpkj-fc-alipay/v1', '/custom-payment/create', [
            'methods' => 'POST',
            'callback' => [$this, 'createPaymentOrder'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'customer_email' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($param) {
                        return is_email($param);
                    }
                ],
                'items' => [
                    'required' => true,
                    'type' => 'array',
                    'validate_callback' => function($param) {
                        return is_array($param) && !empty($param);
                    }
                ]
            ]
        ]);

        // Get payment status
        register_rest_route('wpkj-fc-alipay/v1', '/custom-payment/status/(?P<order_hash>[a-zA-Z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getPaymentStatus'],
            'permission_callback' => [$this, 'checkPermission']
        ]);
    }

    /**
     * Check permission for API access
     * 
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function checkPermission($request)
    {
        // Allow access for authenticated users with manage_options capability
        // You can customize this based on your security requirements
        $hasPermission = current_user_can('manage_options');
        
        // Allow filter to customize permission check
        return apply_filters('wpkj_fc_alipay/custom_payment/check_permission', $hasPermission, $request);
    }

    /**
     * Create payment order
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function createPaymentOrder($request)
    {
        try {
            $customerEmail = sanitize_email($request->get_param('customer_email'));
            $items = $request->get_param('items');

            // Create PaymentIntent
            $paymentIntent = new PaymentIntent();
            $paymentIntent->setCustomerEmail($customerEmail);

            // Create PaymentItems
            $paymentItems = [];
            foreach ($items as $itemData) {
                $paymentItem = new PaymentItem();
                
                // Set item name
                $paymentItem->setItemName(sanitize_text_field($itemData['name']));
                
                // Set price (in cents)
                $paymentItem->setPrice(intval($itemData['price']));
                
                // Set quantity
                if (isset($itemData['quantity'])) {
                    $paymentItem->setQuantity(intval($itemData['quantity']));
                }
                
                // Set payment type
                $paymentType = isset($itemData['payment_type']) ? sanitize_text_field($itemData['payment_type']) : 'onetime';
                $paymentItem->setPaymentType($paymentType);
                
                // Set subscription info if payment type is subscription
                if ($paymentType === 'subscription' && isset($itemData['subscription_info'])) {
                    $subscriptionInfo = [
                        'signup_fee' => intval($itemData['subscription_info']['signup_fee'] ?? 0),
                        'times' => intval($itemData['subscription_info']['times'] ?? 0),
                        'repeat_interval' => sanitize_text_field($itemData['subscription_info']['repeat_interval'] ?? 'monthly')
                    ];
                    $paymentItem->setSubscriptionInfo($subscriptionInfo);
                }
                
                $paymentItems[] = $paymentItem;
            }

            $paymentIntent->setLineItems($paymentItems);

            // Create payment order
            $service = new CustomPaymentService();
            $result = $service->createPaymentOrder($paymentIntent);

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            Logger::error('Custom Payment API: Failed to create order', [
                'error' => $e->getMessage()
            ]);

            return new \WP_Error(
                'create_order_failed',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    /**
     * Get payment status
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getPaymentStatus($request)
    {
        try {
            $orderHash = sanitize_text_field($request->get_param('order_hash'));

            $service = new CustomPaymentService();
            $result = $service->getPaymentStatus($orderHash);

            if ($result['status'] === 'error') {
                return new \WP_Error(
                    'get_status_failed',
                    $result['message'],
                    ['status' => 404]
                );
            }

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            Logger::error('Custom Payment API: Failed to get status', [
                'error' => $e->getMessage()
            ]);

            return new \WP_Error(
                'get_status_failed',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }
}
