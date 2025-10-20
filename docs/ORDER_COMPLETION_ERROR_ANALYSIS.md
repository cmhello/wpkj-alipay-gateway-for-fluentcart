# FluentCart Order Completion Error Analysis

## Error Message
```
"You have already completed this order."
```

## Source Location
**File:** `/wp-content/plugins/fluent-cart/api/Checkout/CheckoutApi.php`  
**Line:** 67  
**Method:** `CheckoutApi::placeOrder()`

---

## Trigger Conditions (Complete Analysis)

### Primary Trigger Code
```php
// Lines 57-68 in CheckoutApi.php
if ($prevOrder &&
    (
        in_array($prevOrder->status, Status::getOrderSuccessStatuses()) ||
        $prevOrder->payment_status != Status::PAYMENT_PENDING
    )
) {
    wp_send_json([
        'status'  => 'failed',
        'message' => __('You have already completed this order.', 'fluent-cart'),
    ]);
}
```

### Condition Breakdown

#### Condition 1: Previous Order Exists
```php
$prevOrder = $cart->order;
```
- The cart must be associated with an existing order
- Retrieved from: `Cart` model's `order` relationship

#### Condition 2A: Order Status is "Success"
```php
in_array($prevOrder->status, Status::getOrderSuccessStatuses())
```

**Success Statuses Include:**
- `processing` - Order is being processed
- `completed` - Order has been completed

**Defined in:** `FluentCart\App\Helpers\Status::getOrderSuccessStatuses()`
```php
public static function getOrderSuccessStatuses()
{
    return [
        self::ORDER_COMPLETED,   // 'completed'
        self::ORDER_PROCESSING,  // 'processing'
    ];
}
```

#### Condition 2B: Payment Status is NOT Pending
```php
$prevOrder->payment_status != Status::PAYMENT_PENDING
```

**Will Trigger When Payment Status Is:**
- `paid` - Payment completed successfully
- `partially_paid` - Partial payment received
- `failed` - Payment failed
- `refunded` - Full refund issued
- `partially_refunded` - Partial refund issued
- `authorized` - Payment authorized but not captured

**Will NOT Trigger When:**
- `pending` - Payment is still pending (allows retry)

---

## Complete Trigger Scenarios

### Scenario 1: Completed Order ✅
**Cart has previous order with:**
- Order Status: `completed` OR `processing`
- Payment Status: Any value

**Result:** Error triggered immediately

### Scenario 2: Paid Order ✅
**Cart has previous order with:**
- Order Status: Any value (even `on-hold`, `failed`, `canceled`)
- Payment Status: `paid`

**Result:** Error triggered immediately

### Scenario 3: Partially Paid Order ✅
**Cart has previous order with:**
- Order Status: Any value
- Payment Status: `partially_paid`

**Result:** Error triggered immediately

### Scenario 4: Failed Payment (Non-Pending) ✅
**Cart has previous order with:**
- Order Status: Any value
- Payment Status: `failed`

**Result:** Error triggered (prevents retry on same cart)

### Scenario 5: Refunded Order ✅
**Cart has previous order with:**
- Order Status: Any value
- Payment Status: `refunded` OR `partially_refunded`

**Result:** Error triggered immediately

### Scenario 6: Authorized Payment ✅
**Cart has previous order with:**
- Order Status: Any value
- Payment Status: `authorized`

**Result:** Error triggered immediately

---

## When Error WILL NOT Trigger

### Safe Scenario 1: Pending Payment ✅
**Cart has previous order with:**
- Order Status: `on-hold`, `failed`, or `canceled`
- Payment Status: `pending`

**Result:** Allowed to proceed (payment retry)

### Safe Scenario 2: No Previous Order ✅
**Cart conditions:**
- No associated order (`$cart->order` is null)
- Cart stage: NOT `completed`

**Result:** Allowed to proceed (new order)

### Safe Scenario 3: Completed Cart Without Order ✅
**Cart conditions:**
- Cart stage: `completed`

**Result:** Different error: "Cart is empty or already completed"

---

## Related Cart Validation

### Cart Stage Check (Lines 52-56)
```php
if (!$cart || !$cart->cart_data || $cart->stage === 'completed') {
    wp_send_json([
        'status'  => 'failed',
        'message' => __('Cart is empty or already completed', 'fluent-cart'),
    ]);
}
```

**This prevents:**
- Empty carts from processing
- Completed carts from reprocessing

---

## Business Logic Explanation

### Purpose of This Validation
1. **Prevent Duplicate Orders:** Stop users from submitting the same order multiple times
2. **Protect Against Double Payment:** Ensure paid orders can't be paid again
3. **Cart Reuse Prevention:** Block completed carts from creating new orders
4. **Payment Status Integrity:** Maintain order-payment status consistency

### FluentCart's Cart-Order Relationship
```
Cart (cart_hash) → Order (order_id) → Transactions
     ↓
  stage: pending/completed
```

**Key Points:**
- Each cart can only have ONE associated order via `order_id`
- Once order is created, cart becomes "locked" to that order
- Cart with `stage='completed'` cannot be reused
- `cart_hash` determines cart uniqueness (based on products + customer)

---

## How to Avoid This Error

### For Regular Payments (Single Purchase)

#### Solution 1: Clear Cart Association (Recommended)
After successful payment confirmation:
```php
$cart = Cart::query()->where('order_id', $order->id)->first();
if ($cart) {
    $cart->order_id = null;
    $cart->stage = 'completed';
    $cart->save();
}
```

**Effect:** Allows repeat purchases of same product

#### Solution 2: Create New Cart Hash
Change cart contents (quantity/price/product):
```php
// Any change to cart will generate new cart_hash
// New hash = New cart = New order allowed
```

### For Subscription Renewals

#### Solution: Use Renewal Order Type
```php
// FluentCart creates NEW order with type='renewal'
// Does NOT reuse original subscription cart
$renewalOrder = Order::create([
    'type' => 'renewal',
    'subscription_id' => $subscription->id,
    // ... other fields
]);
```

---

## Debugging Checklist

When encountering this error, check:

1. **Cart Status:**
   ```sql
   SELECT id, cart_hash, order_id, stage, created_at 
   FROM fct_carts 
   WHERE cart_hash = '{hash}';
   ```

2. **Associated Order:**
   ```sql
   SELECT id, uuid, status, payment_status, type
   FROM fct_orders
   WHERE id = {cart.order_id};
   ```

3. **Order Statuses:**
   - Is order status in `['completed', 'processing']`?
   - Is payment status NOT `'pending'`?

4. **Transaction Status:**
   ```sql
   SELECT uuid, status, payment_method, created_at
   FROM fct_order_transactions
   WHERE order_id = {order.id}
   ORDER BY id DESC LIMIT 1;
   ```

---

## Status Constants Reference

### Order Statuses (Status.php)
```php
const ORDER_PROCESSING = 'processing';  // ✅ Triggers error
const ORDER_COMPLETED = 'completed';    // ✅ Triggers error
const ORDER_ON_HOLD = 'on-hold';        // ❌ Does NOT trigger (if payment=pending)
const ORDER_CANCELED = 'canceled';      // ❌ Does NOT trigger (if payment=pending)
const ORDER_FAILED = 'failed';          // ❌ Does NOT trigger (if payment=pending)
```

### Payment Statuses (Status.php)
```php
const PAYMENT_PENDING = 'pending';                    // ❌ Allows retry
const PAYMENT_PAID = 'paid';                          // ✅ Triggers error
const PAYMENT_PARTIALLY_PAID = 'partially_paid';      // ✅ Triggers error
const PAYMENT_FAILED = 'failed';                      // ✅ Triggers error
const PAYMENT_REFUNDED = 'refunded';                  // ✅ Triggers error
const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded'; // ✅ Triggers error
const PAYMENT_AUTHORIZED = 'authorized';              // ✅ Triggers error
```

---

## Recommendations for Alipay Integration

### Payment Confirmation Flow
```php
// In PaymentProcessor::confirmPaymentSuccess()

// 1. Update transaction status
$transaction->status = Status::TRANSACTION_SUCCEEDED;
$transaction->save();

// 2. Sync order statuses (FluentCart handles this)
(new StatusHelper($order))->syncOrderStatuses($transaction);

// 3. CRITICAL: Clear cart association
OrderService::clearCartOrderAssociation($order, 'payment_confirmation');
```

### Clear Cart Implementation
```php
// In OrderService.php
public static function clearCartOrderAssociation(Order $order, string $context = 'payment_confirmation'): bool
{
    $cart = Cart::query()->where('order_id', $order->id)->first();
    
    if (!$cart) {
        return false;
    }
    
    $cart->order_id = null;
    $cart->stage = 'completed';
    $cart->save();
    
    return true;
}
```

**Why This Works:**
- Setting `order_id = null` breaks cart-order link
- Setting `stage = 'completed'` prevents cart reuse
- FluentCart will create NEW cart for next purchase
- Allows repeat purchases of same product

---

## Summary

**Error Triggers When:**
```
(Order Status = 'completed' OR 'processing') 
OR 
(Payment Status != 'pending')
```

**Error Does NOT Trigger When:**
```
No previous order 
OR 
(Payment Status = 'pending' AND Order Status != 'completed' AND Order Status != 'processing')
```

**Best Practice:**
Always clear cart's `order_id` and set `stage='completed'` after successful payment to enable repeat purchases.

---

## Version Information
- FluentCart Version: Latest (as of analysis)
- Analysis Date: 2025-10-20
- Analyzed By: Code Review System
