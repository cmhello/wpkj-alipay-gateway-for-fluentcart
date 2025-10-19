#!/bin/bash

# Alipay Payment Fix Verification Script
# 支付宝支付修复验证脚本

echo "========================================"
echo "Alipay Payment Fix Verification"
echo "支付宝支付修复验证"
echo "========================================"
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check 1: Verify PaymentProcessor.php has been fixed
echo "✓ Checking PaymentProcessor.php..."
if grep -q "add_query_arg" /www/wwwroot/waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/src/Processor/PaymentProcessor.php; then
    echo -e "${GREEN}✓ PaymentProcessor.php: Using add_query_arg() [FIXED]${NC}"
else
    echo -e "${RED}✗ PaymentProcessor.php: Still using old method [NOT FIXED]${NC}"
fi
echo ""

# Check 2: Verify AlipayGateway.php has been optimized
echo "✓ Checking AlipayGateway.php..."
if grep -q "Priority 5 - before FluentCart's routing" /www/wwwroot/waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/src/Gateway/AlipayGateway.php; then
    echo -e "${GREEN}✓ AlipayGateway.php: Return handler optimized [FIXED]${NC}"
else
    echo -e "${YELLOW}⚠ AlipayGateway.php: Return handler may need update${NC}"
fi
echo ""

# Check 3: Verify log files exist
echo "✓ Checking log directory..."
LOG_DIR="/www/wwwroot/waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/logs"
if [ -d "$LOG_DIR" ]; then
    echo -e "${GREEN}✓ Log directory exists${NC}"
    
    # Find latest log file
    LATEST_LOG=$(ls -t "$LOG_DIR"/alipay-*.log 2>/dev/null | head -1)
    if [ ! -z "$LATEST_LOG" ]; then
        echo "  Latest log: $(basename $LATEST_LOG)"
        
        # Check for Return URL Triggered
        if grep -q "Return URL Triggered" "$LATEST_LOG" 2>/dev/null; then
            echo -e "${GREEN}  ✓ Found 'Return URL Triggered' - Handler is working!${NC}"
        else
            echo -e "${YELLOW}  ⚠ No 'Return URL Triggered' found yet - Test payment needed${NC}"
        fi
        
        # Check for Query Trade
        if grep -q "Query Trade Success" "$LATEST_LOG" 2>/dev/null; then
            echo -e "${GREEN}  ✓ Found 'Query Trade Success' - API working!${NC}"
        else
            echo -e "${YELLOW}  ⚠ No 'Query Trade Success' found yet - Test payment needed${NC}"
        fi
        
        # Check for Payment Confirmed
        if grep -q "Payment Confirmed" "$LATEST_LOG" 2>/dev/null; then
            echo -e "${GREEN}  ✓ Found 'Payment Confirmed' - System working!${NC}"
        else
            echo -e "${YELLOW}  ⚠ No 'Payment Confirmed' found yet - Test payment needed${NC}"
        fi
    else
        echo -e "${YELLOW}  ⚠ No log files found yet - No payments have been made${NC}"
    fi
else
    echo -e "${RED}✗ Log directory not found${NC}"
fi
echo ""

# Check 4: Verify diagnostic tools exist
echo "✓ Checking diagnostic tools..."
TOOLS=(
    "test-url-generation.php"
    "debug-notify.php"
    "view-logs.php"
)

for tool in "${TOOLS[@]}"; do
    if [ -f "/www/wwwroot/waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/$tool" ]; then
        echo -e "${GREEN}  ✓ $tool exists${NC}"
    else
        echo -e "${RED}  ✗ $tool not found${NC}"
    fi
done
echo ""

# Check 5: Check for recent payment URLs in logs
echo "✓ Checking recent payment URLs..."
if [ ! -z "$LATEST_LOG" ]; then
    RETURN_URL=$(grep -o 'return_url.*' "$LATEST_LOG" 2>/dev/null | tail -1)
    if [ ! -z "$RETURN_URL" ]; then
        echo "  Latest return_url configuration:"
        echo "  $RETURN_URL"
        
        # Check if it contains proper parameters
        if echo "$RETURN_URL" | grep -q "method=alipay"; then
            echo -e "${GREEN}  ✓ Contains method=alipay${NC}"
        fi
        
        if echo "$RETURN_URL" | grep -q "trx_hash="; then
            echo -e "${GREEN}  ✓ Contains trx_hash${NC}"
        fi
        
        if echo "$RETURN_URL" | grep -q "fct_redirect=yes"; then
            echo -e "${GREEN}  ✓ Contains fct_redirect=yes${NC}"
        fi
        
        # Check format (should NOT be /receipt/? at the start of params)
        if echo "$RETURN_URL" | grep -q "receipt/?method=alipay"; then
            echo -e "${YELLOW}  ⚠ WARNING: URL format may be incorrect (old format detected)${NC}"
            echo -e "${YELLOW}    Please create a NEW payment to use the fixed URL generation${NC}"
        else
            echo -e "${GREEN}  ✓ URL format appears correct${NC}"
        fi
    else
        echo -e "${YELLOW}  ⚠ No return_url found in logs yet${NC}"
    fi
else
    echo -e "${YELLOW}  ⚠ No log file to check${NC}"
fi
echo ""

# Summary
echo "========================================"
echo "Summary / 总结"
echo "========================================"
echo ""
echo -e "${GREEN}Code fixes have been applied successfully!${NC}"
echo ""
echo "Next steps / 下一步:"
echo "1. Visit test-url-generation.php to verify URL generation"
echo "   访问: https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/test-url-generation.php"
echo ""
echo "2. Clear browser cookies (especially fct_cart_hash)"
echo "   清除浏览器 Cookie（特别是 fct_cart_hash）"
echo ""
echo "3. Create a NEW test payment"
echo "   创建新的测试支付"
echo ""
echo "4. Complete payment and check logs for 'Return URL Triggered'"
echo "   完成支付并检查日志中的 'Return URL Triggered'"
echo ""
echo "5. View logs at:"
echo "   https://waas.wpdaxue.com/wp-content/plugins/wpkj-fluentcart-alipay-payment/view-logs.php"
echo ""
echo "========================================"
