# PayPlus Gateway - Automatic Order Cleanup Feature

## Overview

This feature automatically cancels pending PayPlus orders when a customer starts a new payment process. This prevents issues with:

- Stock reservation conflicts
- Coupon usage problems
- Multiple pending orders for the same customer

## How It Works

### 1. Before Order Placement

- When a customer clicks "Place Order", the system first checks for any existing pending PayPlus orders for that customer
- If found, these orders are automatically canceled before creating the new order
- Stock is released and coupon usage is restored for canceled orders

### 2. Abandoned Order Cleanup

- Orders that remain pending for more than 30 minutes are automatically canceled when the customer returns to checkout/cart
- This handles cases where customers abandon the payment process

### 3. AJAX Cleanup Controller

- Provides immediate cleanup via `/payplus_gateway/ws/cleanuppendingorders` endpoint
- Called automatically before order placement (if feature is enabled)
- Can be called manually for testing purposes

## Configuration

The feature can be enabled/disabled in:
**Admin Panel > Stores > Configuration > Sales > Payment Methods > PayPlus - Payment Gateway > Order Configuration**

Setting: **Auto-cancel pending orders**

- **Yes**: Enable automatic cleanup (recommended)
- **No**: Disable automatic cleanup

## Technical Implementation

### Files Added/Modified:

1. **Observer/BeforePlaceOrder.php** - Cancels pending orders before new order creation
2. **Observer/CleanupAbandonedOrders.php** - Handles cleanup of abandoned orders
3. **Controller/Ws/CleanupPendingOrders.php** - AJAX endpoint for immediate cleanup
4. **events.xml** - Event registration for observers
5. **system.xml** - Admin configuration option
6. **ConfigProvider.php** - Exposes config to frontend
7. **payplus_gateway.js** - Frontend implementation

### Events Used:

- `sales_model_service_quote_submit_before` - Before order placement
- `checkout_onepage_controller_success_action` - On success page
- `checkout_cart_index` - On cart page

### Order Criteria for Cancellation:

- Payment method starts with "payplus\_"
- Order state is "new" or "pending_payment"
- Order status is "pending" or "pending_payment"
- Order created within last 24 hours
- Same customer (by ID or email)

## Benefits

1. **Stock Management**: Prevents stock being held indefinitely by abandoned orders
2. **Coupon Management**: Prevents coupon usage conflicts and double usage
3. **Order Management**: Keeps order list clean by removing abandoned orders
4. **User Experience**: Prevents confusion from multiple pending orders
5. **Configurable**: Can be disabled if not needed

## Logging

All cleanup activities are logged to the PayPlus debug log with details including:

- Customer ID/email
- Number of orders canceled
- Order IDs affected
- Any errors encountered

## Testing

To test the feature:

1. Enable the setting in admin
2. Place a PayPlus order but don't complete payment
3. Return to checkout and place another order
4. Verify the first order was automatically canceled
5. Check logs for cleanup activity

## Backwards Compatibility

- Feature is disabled by default
- When disabled, no cleanup occurs (existing behavior)
- No database schema changes required
- Safe to enable/disable at any time
