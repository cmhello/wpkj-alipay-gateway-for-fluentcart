<?php

namespace WPKJFluentCart\Alipay\Services;

use FluentCart\App\Services\CustomPayment\PaymentIntent;
use FluentCart\App\Services\CustomPayment\PaymentItem;
use WPKJFluentCart\Alipay\Utils\Logger;

/**
 * Custom Payment Service for Alipay
 * 
 * Provides support for FluentCart's custom_payment system,
 * allowing external systems to create payment orders through Alipay gateway.
 * 
 * @since 1.0.8
 */
class CustomPaymentService
{
    /**
     * Create a custom payment order
     * 
     * This method accepts a PaymentIntent object and creates an order
     * that can be paid through the Alipay gateway.
     * 
     * @param PaymentIntent $paymentIntent Payment intent with line items and customer info
     * @return array Response with order hash and payment URL
     * @throws \Exception
     */
    public function createPaymentOrder(PaymentIntent $paymentIntent): array
    {
        try {
            Logger::info('Custom Payment: Creating order from PaymentIntent', [
                'customer_email' => $paymentIntent->getCustomerEmail(),
                'line_items_count' => count($paymentIntent->getLineItems())
            ]);

            // Validate payment intent
            $this->validatePaymentIntent($paymentIntent);

            // Convert PaymentIntent to FluentCart order data
            $orderData = $this->convertPaymentIntentToOrderData($paymentIntent);

            // Create order through FluentCart API
            $order = $this->createFluentCartOrder($orderData);

            // Generate custom payment link
            $paymentUrl = \FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink($order['uuid']);

            Logger::info('Custom Payment: Order created successfully', [
                'order_id' => $order['id'],
                'order_hash' => $order['uuid'],
                'payment_url' => $paymentUrl
            ]);

            return [
                'status' => 'success',
                'message' => __('Payment order created successfully', 'wpkj-fluentcart-alipay-payment'),
                'data' => [
                    'order_id' => $order['id'],
                    'order_hash' => $order['uuid'],
                    'payment_url' => $paymentUrl,
                    'total_amount' => $order['total_amount']
                ]
            ];

        } catch (\Exception $e) {
            Logger::error('Custom Payment: Failed to create order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Validate payment intent
     * 
     * @param PaymentIntent $paymentIntent
     * @throws \InvalidArgumentException
     */
    protected function validatePaymentIntent(PaymentIntent $paymentIntent): void
    {
        // Validate customer email
        $customerEmail = $paymentIntent->getCustomerEmail();
        if (empty($customerEmail) || !is_email($customerEmail)) {
            throw new \InvalidArgumentException(
                __('Invalid customer email address', 'wpkj-fluentcart-alipay-payment') // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is translated and safe
            );
        }

        // Validate line items
        $lineItems = $paymentIntent->getLineItems();
        if (empty($lineItems)) {
            throw new \InvalidArgumentException(
                __('Payment intent must contain at least one line item', 'wpkj-fluentcart-alipay-payment') // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is translated and safe
            );
        }

        // Validate each line item
        foreach ($lineItems as $item) {
            if (!($item instanceof PaymentItem)) {
                throw new \InvalidArgumentException(
                    __('All line items must be PaymentItem instances', 'wpkj-fluentcart-alipay-payment') // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is translated and safe
                );
            }
        }
    }

    /**
     * Convert PaymentIntent to FluentCart order data
     * 
     * @param PaymentIntent $paymentIntent
     * @return array
     */
    protected function convertPaymentIntentToOrderData(PaymentIntent $paymentIntent): array
    {
        $lineItems = $paymentIntent->getLineItems();
        $customerEmail = $paymentIntent->getCustomerEmail();

        $items = [];
        $subtotal = 0;

        foreach ($lineItems as $paymentItem) {
            $itemData = $paymentItem->toArray();
            
            $item = [
                'item_name' => $itemData['item_name'],
                'price' => $itemData['price'], // Price in cents
                'quantity' => $itemData['quantity'],
                'line_total' => $itemData['line_total'],
                'payment_type' => $itemData['payment_type']
            ];

            // Add subscription info if present
            if ($itemData['payment_type'] === 'subscription' && !empty($itemData['subscription_info'])) {
                $item['subscription_info'] = $itemData['subscription_info'];
            }

            $items[] = $item;
            $subtotal += $itemData['line_total'];
        }

        return [
            'customer_email' => $customerEmail,
            'items' => $items,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'payment_method' => 'alipay',
            'currency' => \FluentCart\Api\CurrencySettings::get('currency'),
            'source' => 'custom_payment'
        ];
    }

    /**
     * Create FluentCart order
     * 
     * @param array $orderData
     * @return array Created order
     * @throws \Exception
     */
    protected function createFluentCartOrder(array $orderData): array
    {
        // This is a simplified version. In a real implementation,
        // you would use FluentCart's checkout API to create the order.
        
        // For now, we'll use a filter hook to allow custom order creation
        $order = apply_filters(
            'wpkj_fc_alipay/custom_payment/create_order',
            null,
            $orderData
        );

        if (!$order) {
            throw new \Exception(
                __('Failed to create FluentCart order. Please ensure the order creation hook is properly implemented.', 'wpkj-fluentcart-alipay-payment') // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is translated and safe
            );
        }

        return $order;
    }

    /**
     * Get payment status by order hash
     * 
     * @param string $orderHash Order UUID
     * @return array Payment status information
     */
    public function getPaymentStatus(string $orderHash): array
    {
        try {
            $order = \FluentCart\App\Models\Order::query()
                ->where('uuid', $orderHash)
                ->first();

            if (!$order) {
                throw new \Exception(__('Order not found', 'wpkj-fluentcart-alipay-payment')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is translated and safe
            }

            return [
                'status' => 'success',
                'data' => [
                    'order_hash' => $order->uuid,
                    'payment_status' => $order->payment_status,
                    'order_status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'paid_amount' => $order->paid,
                    'is_paid' => $order->payment_status === \FluentCart\App\Helpers\Status::PAYMENT_PAID
                ]
            ];

        } catch (\Exception $e) {
            Logger::error('Custom Payment: Failed to get payment status', [
                'order_hash' => $orderHash,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
