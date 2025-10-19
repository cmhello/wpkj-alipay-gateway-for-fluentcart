# WPKJ FluentCart Alipay Payment Gateway - Development Plan

## 1. Architecture Overview

### 1.1 FluentCart Payment Gateway Architecture Analysis

FluentCart uses a **modular, object-oriented architecture** with the following core components:

**Core Components:**
- **`PaymentGatewayInterface`**: Defines the contract for all payment gateways
- **`AbstractPaymentGateway`**: Base abstract class providing common functionality
- **`GatewayManager`**: Singleton pattern manager for registering and managing payment gateways
- **`BaseGatewaySettings`**: Abstract settings handler with meta-based storage
- **`PaymentInstance`**: Encapsulates order, transaction, and subscription data

**Key Integration Points:**
- Gateways are registered via the `fluent_cart/register_payment_methods` hook
- Settings are stored in the `fct_meta` table with key pattern: `fluent_cart_payment_settings_{gateway_slug}`
- Payment processing flow: Order Creation → Payment Intent → Gateway Processing → Webhook/IPN → Order Completion

---

## 2. Plugin Structure Design

### 2.1 Directory Structure

```
wpkj-fluentcart-alipay-payment/
├── wpkj-fluentcart-alipay-payment.php    # Main plugin file
├── composer.json                          # Composer dependencies (Alipay SDK)
├── languages/                             # Translation files
│   ├── wpkj-fluentcart-alipay-payment.pot
│   └── wpkj-fluentcart-alipay-payment-zh_CN.po
├── src/
│   ├── Gateway/
│   │   ├── AlipayGateway.php             # Main gateway class
│   │   └── AlipaySettingsBase.php        # Settings handler
│   ├── API/
│   │   ├── AlipayAPI.php                 # Alipay API communication
│   │   └── AlipaySignature.php           # Signature generation/verification
│   ├── Processor/
│   │   └── PaymentProcessor.php          # Payment processing logic
│   ├── Webhook/
│   │   └── NotifyHandler.php             # Asynchronous notification handler
│   ├── Utils/
│   │   ├── Config.php                    # Configuration helper
│   │   ├── Logger.php                    # Logging utility
│   │   └── Helper.php                    # General helper functions
│   └── Detector/
│       └── ClientDetector.php            # Detect Alipay client environment
├── assets/
│   ├── js/
│   │   ├── admin.js                      # Admin panel JS
│   │   └── checkout.js                   # Frontend checkout handler
│   └── css/
│       ├── admin.css                     # Admin styles
│       └── frontend.css                  # Frontend styles
└── readme.txt                            # WordPress plugin readme
```

---

## 3. Complete Payment Flow

```
User Checkout
    ↓
FluentCart Order Creation
    ↓
AlipayGateway::makePaymentFromPaymentInstance()
    ↓
PaymentProcessor::processSinglePayment()
    ↓
AlipayAPI::createPayment()
    ↓
Redirect to Alipay Gateway
    ↓
User Completes Payment
    ↓
    ├─→ Synchronous Return (return_url)
    │   └─→ Display receipt page
    │
    └─→ Asynchronous Notification (notify_url)
        ↓
        NotifyHandler::processNotify()
        ↓
        Verify Signature
        ↓
        PaymentProcessor::confirmPaymentSuccess()
        ↓
        Update Transaction & Order Status
        ↓
        Send "success" to Alipay
```

---

## 4. Development Phases

**Phase 1: Foundation (Week 1-2)**
- Set up plugin structure
- Implement core classes
- Create settings interface

**Phase 2: Payment Flow (Week 3-4)**
- Implement payment processing
- Build API integration
- Add webhook handler

**Phase 3: Testing & Refinement (Week 5)**
- Unit testing
- Integration testing
- Bug fixes and optimization

**Phase 4: Documentation & Release (Week 6)**
- Write documentation
- Prepare release package
- Submit for review

---

## 5. Security Considerations

### 5.1 Data Validation
- All user inputs sanitized using WordPress functions
- Signature verification for all Alipay notifications
- HTTPS enforcement for production mode

### 5.2 Key Storage
- Private keys encrypted using WordPress built-in functions
- Support defining keys via wp-config.php

### 5.3 Anti-Tampering
- Amount verification matches order total
- Order status check prevents duplicate processing

---

## 6. Internationalization (i18n)

**Text Domain:** `wpkj-fluentcart-alipay-payment`
**Domain Path:** `/languages/`

All user-facing strings must use translation functions.

---

## 7. Key Success Factors

✅ **Architectural Alignment:** Fully compatible with FluentCart's design patterns  
✅ **Code Quality:** PSR-4, WordPress Coding Standards, comprehensive PHPDoc  
✅ **Security First:** Robust signature verification, encrypted key storage  
✅ **i18n Ready:** Full internationalization support  
✅ **Maintainability:** Modular design, clear separation of concerns  

---

Generated: 2025-10-19
Version: 1.0.0
