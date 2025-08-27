#!/bin/bash

# PayPlus Auto-Cancel Test Script
# This script tests the auto-cancel pending orders functionality

echo "=== PayPlus Auto-Cancel Feature Test ==="
echo ""

# Test the cleanup controller endpoint
echo "Testing cleanup controller endpoint..."
curl -X POST "http://localhost/payplus_gateway/ws/cleanuppendingorders" \
  -H "Content-Type: application/json" \
  -s | jq '.' 2>/dev/null || echo "Response received (JSON parse may have failed)"

echo ""
echo "=== Test completed ==="
echo ""
echo "To test the full functionality:"
echo "1. Enable 'Auto-cancel pending orders' in Admin > Payment Methods > PayPlus"
echo "2. Create a test order with PayPlus but don't complete payment"
echo "3. Go back to checkout and create another order"
echo "4. Verify the first order was automatically canceled"
echo "5. Check var/log/payplus.log for cleanup activity"
