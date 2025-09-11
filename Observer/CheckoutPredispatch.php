<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;

class CheckoutPredispatch implements ObserverInterface
{
    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var OrderCollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var CouponCollectionFactory
     */
    protected $couponCollectionFactory;

    /**
     * @var RuleCollectionFactory
     */
    protected $ruleCollectionFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTime;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $timezone;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $sessionManager;

    public function __construct(
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderCollectionFactory $orderCollectionFactory,
        CouponCollectionFactory $couponCollectionFactory,
        RuleCollectionFactory $ruleCollectionFactory,
        LoggerInterface $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\Session $sessionManager
    ) {
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->couponCollectionFactory = $couponCollectionFactory;
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
        $this->timezone = $timezone;
        $this->scopeConfig = $scopeConfig;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            // Get current page information from observer
            $currentPage = $this->getCurrentPageType($observer);
            
            $this->logger->info('PayPlus Gateway Observer - Page accessed: ' . $currentPage);

            // Check if at least one feature is enabled
            $orderCancellationEnabled = $this->isOrderCancellationEnabled();
            $couponRestorationEnabled = $this->isCouponRestorationEnabled();

            if (!$orderCancellationEnabled && !$couponRestorationEnabled) {
                $this->logger->info('PayPlus Gateway Observer - Order cancellation and coupon restoration both disabled');
                return;
            }

            // Get current customer identifier (ID for logged in, email for guest)
            $customerIdentifier = $this->getCurrentCustomerIdentifier();
            if (!$customerIdentifier) {
                $this->logger->info('PayPlus Gateway Observer - No customer identifier found, skipping order processing');
                return;
            }

            // Process orders with duplicate prevention
            $this->processPendingOrders($customerIdentifier, $orderCancellationEnabled, $couponRestorationEnabled, $currentPage);
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error in CheckoutPredispatch observer: ' . $e->getMessage());
        }
    }

    /**
     * Get current page type from event name
     *
     * @param string $eventName
     * @return string
     */
    protected function getCurrentPageType($observer)
    {
        // Get the event name to determine page type
        $eventName = $observer->getEvent()->getName();
        
        if ($eventName === 'controller_action_predispatch_checkout_cart_index') {
            return 'cart';
        } elseif ($eventName === 'controller_action_predispatch_checkout_index_index') {
            return 'checkout';
        }
        
        return 'unknown';
    }

    /**
     * Get current customer identifier - ID for logged in customers, email for guests
     *
     * @return array|null
     */
    protected function getCurrentCustomerIdentifier()
    {
        if ($this->customerSession->isLoggedIn()) {
            return [
                'type' => 'customer_id',
                'value' => $this->customerSession->getCustomerId()
            ];
        }

        // For guest customers, try to get email from quote
        $quote = $this->checkoutSession->getQuote();
        if ($quote && $quote->getId()) {
            $email = $quote->getCustomerEmail();
            if ($email) {
                return [
                    'type' => 'email',
                    'value' => $email
                ];
            }
        }

        return null;
    }

    /**
     * Check if order cancellation is enabled
     *
     * @return bool
     */
    protected function isOrderCancellationEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/orders_config/cancel_pending_orders',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if coupon restoration is enabled
     *
     * @return bool
     */
    protected function isCouponRestorationEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/orders_config/restore_coupon_usage',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Process pending orders for the current customer
     *
     * @param array $customerIdentifier
     * @param bool $cancelOrders
     * @param bool $restoreCoupons
     * @param string $currentPage
     * @return void
     */
    protected function processPendingOrders($customerIdentifier, $cancelOrders, $restoreCoupons, $currentPage = 'unknown')
    {
        // Get session key for tracking processed orders
        $sessionKey = 'payplus_processed_orders_' . $customerIdentifier['type'] . '_' . md5($customerIdentifier['value']);
        $processedOrders = $this->sessionManager->getData($sessionKey) ?: [];
        $processedInThisSession = [];
        
        $this->logger->info('PayPlus Gateway - Processing orders', [
            'customer_type' => $customerIdentifier['type'],
            'customer_identifier' => $customerIdentifier['value'],
            'current_page' => $currentPage,
            'processed_orders_count' => count($processedOrders)
        ]);

        // Get today's date range in store timezone
        $todayStart = $this->timezone->date()->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $this->timezone->date()->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        // Find all pending orders from today for this customer
        $orderCollection = $this->orderCollectionFactory->create();
        
        // Apply customer filter based on type
        if ($customerIdentifier['type'] === 'customer_id') {
            $orderCollection->addFieldToFilter('customer_id', $customerIdentifier['value']);
        } else {
            // For guest customers, filter by email
            $orderCollection->addFieldToFilter('customer_email', $customerIdentifier['value'])
                           ->addFieldToFilter('customer_id', ['null' => true]);
        }
        
        $orderCollection->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('status', 'pending_payment')
            ->addFieldToFilter('created_at', ['gteq' => $todayStart])
            ->addFieldToFilter('created_at', ['lteq' => $todayEnd]);

        $processedInThisSession = [];
        
        foreach ($orderCollection as $order) {
            $orderId = $order->getId();
            
            // Skip if this order was already processed in this session
            if (in_array($orderId, $processedOrders)) {
                $this->logger->info('PayPlus Gateway - Order already processed in session: ' . $orderId);
                continue;
            }

            $couponCode = $order->getCouponCode();
            $hasCoupon = !empty($couponCode);
            
            $this->logger->info('PayPlus Gateway - Processing order', [
                'order_id' => $orderId,
                'has_coupon' => $hasCoupon,
                'coupon_code' => $couponCode,
                'page' => $currentPage
            ]);

            // Handle coupon restoration (can work independently)
            if ($hasCoupon && $restoreCoupons) {
                $this->restoreCouponUsage($couponCode, $order, $customerIdentifier);
            }

            // Handle order cancellation (can work independently)
            if ($cancelOrders) {
                $this->cancelOrder($order, $hasCoupon, $restoreCoupons);
            } elseif ($hasCoupon && $restoreCoupons) {
                // Just restore coupon without canceling order
                $order->addCommentToStatusHistory('Coupon usage restored by PayPlus Gateway', false);
                $order->save();
            }
            
            // Mark order as processed
            $processedInThisSession[] = $orderId;
        }
        
        // Update session with newly processed orders
        if (!empty($processedInThisSession)) {
            $allProcessedOrders = array_unique(array_merge($processedOrders, $processedInThisSession));
            $this->sessionManager->setData($sessionKey, $allProcessedOrders);
            
            $this->logger->info('PayPlus Gateway - Updated session with processed orders', [
                'newly_processed' => count($processedInThisSession),
                'total_processed' => count($allProcessedOrders),
                'page' => $currentPage
            ]);
        }
    }

    /**
     * Restore coupon usage by properly handling both global and per-customer limits
     *
     * @param string $couponCode
     * @param Order $order
     * @param array|null $customerIdentifier
     * @return void
     */
    protected function restoreCouponUsage($couponCode, $order, $customerIdentifier = null)
    {
        try {
            // Get the coupon
            $couponCollection = $this->couponCollectionFactory->create();
            $couponCollection->addFieldToFilter('code', $couponCode);

            $coupon = $couponCollection->getFirstItem();
            if (!$coupon || !$coupon->getId()) {
                $this->logger->warning('PayPlus Gateway - Coupon not found for restoration', [
                    'coupon_code' => $couponCode,
                    'order_id' => $order->getId()
                ]);
                return;
            }

            // Get the sales rule to check usage limits
            $ruleCollection = $this->ruleCollectionFactory->create();
            $rule = $ruleCollection->addFieldToFilter('rule_id', $coupon->getRuleId())->getFirstItem();

            if (!$rule->getId()) {
                $this->logger->warning('PayPlus Gateway - Sales rule not found for coupon', [
                    'coupon_code' => $couponCode,
                    'rule_id' => $coupon->getRuleId(),
                    'order_id' => $order->getId()
                ]);
                return;
            }

            $customerId = $order->getCustomerId();
            
            // Determine customer identifier if not provided
            if ($customerIdentifier === null) {
                if ($customerId) {
                    $customerIdentifier = ['type' => 'customer_id', 'value' => $customerId];
                } else {
                    $customerIdentifier = ['type' => 'customer_email', 'value' => $order->getCustomerEmail()];
                }
            }

            // Handle registered vs guest customers
            if ($customerIdentifier['type'] === 'customer_id') {
                // Registered customer
                $this->restoreRegisteredCustomerCoupon($coupon, $rule, $order, $customerIdentifier['value']);
            } else {
                // Guest customer
                $this->restoreGuestCustomerCoupon($coupon, $rule, $order, $customerIdentifier['value']);
            }

        } catch (\Exception $e) {
            $this->logger->error(
                'PayPlus Gateway - Error restoring coupon usage: ' . $e->getMessage(),
                [
                    'coupon_code' => $couponCode,
                    'order_id' => $order->getId()
                ]
            );
        }
    }

    /**
     * Get customer's usage count for a specific coupon
     *
     * @param string $couponCode
     * @param int $customerId
     * @param int $excludeOrderId
     * @return int
     */
    protected function getCustomerCouponUsage($couponCode, $customerId, $excludeOrderId = null)
    {
        if (!$customerId) {
            return 0;
        }

        try {
            $orderCollection = $this->orderCollectionFactory->create();
            $orderCollection->addFieldToFilter('customer_id', $customerId)
                ->addFieldToFilter('coupon_code', $couponCode)
                ->addFieldToFilter('state', ['in' => [
                    \Magento\Sales\Model\Order::STATE_PROCESSING,
                    \Magento\Sales\Model\Order::STATE_COMPLETE,
                    \Magento\Sales\Model\Order::STATE_CLOSED
                ]]);

            if ($excludeOrderId) {
                $orderCollection->addFieldToFilter('entity_id', ['neq' => $excludeOrderId]);
            }

            return $orderCollection->getSize();
        } catch (\Exception $e) {
            $this->logger->error(
                'PayPlus Gateway - Error checking customer coupon usage: ' . $e->getMessage(),
                [
                    'coupon_code' => $couponCode,
                    'customer_id' => $customerId
                ]
            );
            return 0;
        }
    }

    /**
     * Restore customer-specific coupon usage (for per-customer limits)
     *
     * @param string $couponCode
     * @param int $customerId
     * @param int $orderIdToRestore
     * @return void
     */
    protected function restoreCustomerCouponUsage($couponCode, $customerId, $orderIdToRestore)
    {
        try {
            // Magento tracks per-customer usage in the salesrule_customer table
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
            $connection = $resource->getConnection();

            // Get the coupon
            $couponCollection = $this->couponCollectionFactory->create();
            $couponCollection->addFieldToFilter('code', $couponCode);
            $coupon = $couponCollection->getFirstItem();

            if (!$coupon || !$coupon->getId()) {
                return;
            }

            $ruleId = $coupon->getRuleId();

            // Check if there's a customer usage record
            $customerTable = $resource->getTableName('salesrule_customer');
            $select = $connection->select()
                ->from($customerTable)
                ->where('rule_id = ?', $ruleId)
                ->where('customer_id = ?', $customerId);

            $customerUsageRecord = $connection->fetchRow($select);

            if ($customerUsageRecord && $customerUsageRecord['times_used'] > 0) {
                // Decrement the customer's usage count
                $connection->update(
                    $customerTable,
                    ['times_used' => $customerUsageRecord['times_used'] - 1],
                    [
                        'rule_id = ?' => $ruleId,
                        'customer_id = ?' => $customerId
                    ]
                );

                $this->logger->info(
                    'PayPlus Gateway - Restored customer coupon usage record',
                    [
                        'coupon_code' => $couponCode,
                        'customer_id' => $customerId,
                        'rule_id' => $ruleId,
                        'previous_customer_usage' => $customerUsageRecord['times_used'],
                        'new_customer_usage' => $customerUsageRecord['times_used'] - 1
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'PayPlus Gateway - Error restoring customer coupon usage: ' . $e->getMessage(),
                [
                    'coupon_code' => $couponCode,
                    'customer_id' => $customerId
                ]
            );
        }
    }

    /**
     * Cancel the order
     *
     * @param Order $order
     * @param bool $hasCoupon
     * @param bool $couponWasRestored
     * @return void
     */
    protected function cancelOrder($order, $hasCoupon = false, $couponWasRestored = false)
    {
        try {
            if ($order->canCancel()) {
                $order->cancel();

                // Automatically return stock for each item in the cancelled order
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $stockRegistry = $objectManager->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);

                foreach ($order->getAllItems() as $item) {
                    $productId = $item->getProductId();
                    $qty = $item->getQtyOrdered();
                    $stockItem = $stockRegistry->getStockItem($productId, $order->getStore()->getWebsiteId());
                    $stockItem->setQty($stockItem->getQty() + $qty);
                    $stockItem->setIsInStock(true);
                    $stockRegistry->updateStockItemBySku($item->getSku(), $stockItem);
                }

                if ($hasCoupon && $couponWasRestored) {
                    $comment = 'Order cancelled automatically due to new checkout session. Coupon usage was restored.';
                } elseif ($hasCoupon) {
                    $comment = 'Order cancelled automatically due to new checkout session. Coupon was not restored (feature disabled).';
                } else {
                    $comment = 'Order cancelled automatically due to new checkout session.';
                }

                $order->addCommentToStatusHistory($comment, false, false);
                $order->save();

                $logMessage = $hasCoupon
                    ? 'PayPlus Gateway - Cancelled pending order with coupon'
                    : 'PayPlus Gateway - Cancelled pending order';

                $this->logger->info($logMessage, [
                    'order_id' => $order->getId(),
                    'coupon_code' => $order->getCouponCode(),
                    'customer_id' => $order->getCustomerId(),
                    'had_coupon' => $hasCoupon,
                    'coupon_restored' => $couponWasRestored
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'PayPlus Gateway - Error cancelling order: ' . $e->getMessage(),
                [
                    'order_id' => $order->getId()
                ]
            );
        }
    }

    /**
     * Restore coupon for registered customers
     *
     * @param Coupon $coupon
     * @param \Magento\SalesRule\Model\Rule $rule
     * @param Order $order
     * @param int $customerId
     * @return void
     */
    protected function restoreRegisteredCustomerCoupon($coupon, $rule, $order, $customerId)
    {
        $currentGlobalUsage = $coupon->getTimesUsed();
        $restorationActions = [];

        // Check if this customer actually used this coupon
        $customerUsageCount = $this->getCustomerCouponUsage($coupon->getCode(), $customerId, $order->getId());

        // Restore global usage if applicable
        if ($currentGlobalUsage > 0) {
            $coupon->setTimesUsed($currentGlobalUsage - 1);
            $restorationActions[] = "Global usage: {$currentGlobalUsage} → " . ($currentGlobalUsage - 1);
        }

        // Restore per-customer usage if applicable and customer has usage limits
        if ($customerId && $customerUsageCount > 0 && $rule->getUsesPerCustomer()) {
            $this->restoreCustomerCouponUsage($coupon->getCode(), $customerId, $order->getId());
            $restorationActions[] = "Customer usage restored for customer {$customerId}";
        }

        if (!empty($restorationActions)) {
            $coupon->save();
            $this->logger->info('PayPlus Gateway - Restored coupon usage for registered customer', [
                'coupon_code' => $coupon->getCode(),
                'order_id' => $order->getId(),
                'customer_id' => $customerId,
                'actions' => $restorationActions
            ]);
        }
    }

    /**
     * Restore coupon for guest customers
     *
     * @param Coupon $coupon
     * @param \Magento\SalesRule\Model\Rule $rule
     * @param Order $order
     * @param string $customerEmail
     * @return void
     */
    protected function restoreGuestCustomerCoupon($coupon, $rule, $order, $customerEmail)
    {
        $currentGlobalUsage = $coupon->getTimesUsed();
        $restorationActions = [];

        // For guest customers, we only restore global usage
        // Per-customer limits don't apply to guests in the same way
        if ($currentGlobalUsage > 0) {
            $coupon->setTimesUsed($currentGlobalUsage - 1);
            $restorationActions[] = "Global usage: {$currentGlobalUsage} → " . ($currentGlobalUsage - 1);
        }

        // Check if this specific guest order used the coupon
        $guestUsageCount = $this->getGuestCouponUsage($coupon->getCode(), $customerEmail, $order->getId());
        
        if ($guestUsageCount > 0) {
            $restorationActions[] = "Guest usage confirmed for email {$customerEmail}";
        }

        if (!empty($restorationActions)) {
            $coupon->save();
            $this->logger->info('PayPlus Gateway - Restored coupon usage for guest customer', [
                'coupon_code' => $coupon->getCode(),
                'order_id' => $order->getId(),
                'customer_email' => $customerEmail,
                'actions' => $restorationActions
            ]);
        }
    }

    /**
     * Get guest customer's usage count for a specific coupon
     *
     * @param string $couponCode
     * @param string $customerEmail
     * @param int $excludeOrderId
     * @return int
     */
    protected function getGuestCouponUsage($couponCode, $customerEmail, $excludeOrderId = null)
    {
        try {
            $orderCollection = $this->orderCollectionFactory->create();
            $orderCollection->addFieldToFilter('customer_email', $customerEmail)
                           ->addFieldToFilter('customer_id', ['null' => true])
                           ->addFieldToFilter('coupon_code', $couponCode)
                           ->addFieldToFilter('state', ['neq' => Order::STATE_CANCELED]);

            if ($excludeOrderId) {
                $orderCollection->addFieldToFilter('entity_id', ['neq' => $excludeOrderId]);
            }

            return $orderCollection->getSize();
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error getting guest coupon usage', [
                'error' => $e->getMessage(),
                'coupon_code' => $couponCode,
                'customer_email' => $customerEmail
            ]);
            return 0;
        }
    }
}
