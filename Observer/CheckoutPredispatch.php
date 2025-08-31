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

class CheckoutPredispatch implements ObserverInterface
{
    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var OrderCollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var CouponCollectionFactory
     */
    protected $couponCollectionFactory;

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

    public function __construct(
        CustomerSession $customerSession,
        OrderCollectionFactory $orderCollectionFactory,
        CouponCollectionFactory $couponCollectionFactory,
        LoggerInterface $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->customerSession = $customerSession;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->couponCollectionFactory = $couponCollectionFactory;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
        $this->timezone = $timezone;
        $this->scopeConfig = $scopeConfig;
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
            // Check if at least one feature is enabled
            $orderCancellationEnabled = $this->isOrderCancellationEnabled();
            $couponRestorationEnabled = $this->isCouponRestorationEnabled();

            if (!$orderCancellationEnabled && !$couponRestorationEnabled) {
                return; // Both features disabled, nothing to do
            }

            // Only process for logged-in customers
            if (!$this->customerSession->isLoggedIn()) {
                return;
            }

            $customerId = $this->customerSession->getCustomerId();
            $this->processPendingOrders($customerId, $orderCancellationEnabled, $couponRestorationEnabled);
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error in CheckoutPredispatch observer: ' . $e->getMessage());
        }
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
     * @param int $customerId
     * @param bool $cancelOrders
     * @param bool $restoreCoupons
     * @return void
     */
    protected function processPendingOrders($customerId, $cancelOrders, $restoreCoupons)
    {
        // Get today's date range in store timezone
        $todayStart = $this->timezone->date()->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $this->timezone->date()->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        // Find all pending orders from today for this customer
        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('status', 'pending_payment')
            ->addFieldToFilter('created_at', ['gteq' => $todayStart])
            ->addFieldToFilter('created_at', ['lteq' => $todayEnd]);

        foreach ($orderCollection as $order) {
            $couponCode = $order->getCouponCode();
            $hasCoupon = !empty($couponCode);

            // Handle coupon restoration (can work independently)
            if ($hasCoupon && $restoreCoupons) {
                $this->restoreCouponUsage($couponCode, $order);
            }

            // Handle order cancellation (can work independently)
            if ($cancelOrders) {
                $this->cancelOrder($order, $hasCoupon, $restoreCoupons);
            } elseif ($hasCoupon && $restoreCoupons) {
                // If only coupon restoration is enabled, just log the action
                $this->logger->info(
                    'PayPlus Gateway - Restored coupon usage without canceling order',
                    [
                        'order_id' => $order->getId(),
                        'coupon_code' => $couponCode,
                        'customer_id' => $order->getCustomerId()
                    ]
                );
            }
        }
    }

    /**
     * Restore coupon usage by properly handling both global and per-customer limits
     *
     * @param string $couponCode
     * @param Order $order
     * @return void
     */
    protected function restoreCouponUsage($couponCode, $order)
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

            $customerId = $order->getCustomerId();
            $currentGlobalUsage = $coupon->getTimesUsed();

            // Get the sales rule to check usage limits
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $salesRule = $objectManager->create(\Magento\SalesRule\Model\Rule::class)->load($coupon->getRuleId());

            $globalUsageLimit = $coupon->getUsageLimit();
            $perCustomerLimit = $coupon->getUsageLimitPerCustomer();

            // Check if this customer actually used this coupon
            $customerUsageCount = $this->getCustomerCouponUsage($couponCode, $customerId, $order->getId());

            $restorationActions = [];

            // Restore global usage if applicable
            if ($currentGlobalUsage > 0) {
                $coupon->setTimesUsed($currentGlobalUsage - 1);
                $restorationActions[] = "Global usage: {$currentGlobalUsage} → " . ($currentGlobalUsage - 1);
            }

            // Restore per-customer usage if applicable
            if ($customerId && $customerUsageCount > 0) {
                $this->restoreCustomerCouponUsage($couponCode, $customerId, $order->getId());
                $restorationActions[] = "Customer usage restored for customer {$customerId}";
            }

            if (!empty($restorationActions)) {
                $coupon->save();

                $this->logger->info(
                    'PayPlus Gateway - Properly restored coupon usage',
                    [
                        'coupon_code' => $couponCode,
                        'order_id' => $order->getId(),
                        'customer_id' => $customerId,
                        'actions' => $restorationActions,
                        'global_limit' => $globalUsageLimit ?: 'Unlimited',
                        'per_customer_limit' => $perCustomerLimit ?: 'Unlimited',
                        'previous_global_usage' => $currentGlobalUsage,
                        'new_global_usage' => $currentGlobalUsage > 0 ? $currentGlobalUsage - 1 : 0
                    ]
                );
            } else {
                $this->logger->info(
                    'PayPlus Gateway - No coupon usage to restore',
                    [
                        'coupon_code' => $couponCode,
                        'order_id' => $order->getId(),
                        'customer_id' => $customerId,
                        'reason' => 'Coupon was not actually used or already at zero usage'
                    ]
                );
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
}
