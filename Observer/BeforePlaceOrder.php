<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\QuoteRepository;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\Rule\CustomerFactory as RuleCustomerFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\UsageFactory as CouponUsageFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Payplus\PayplusGateway\Logger\Logger;

class BeforePlaceOrder implements ObserverInterface
{
    protected $orderCollectionFactory;
    protected $quoteRepository;
    protected $stockManagement;
    protected $couponFactory;
    protected $ruleCustomerFactory;
    protected $couponUsageFactory;
    protected $customerSession;
    protected $scopeConfig;
    protected $logger;

    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        QuoteRepository $quoteRepository,
        StockManagementInterface $stockManagement,
        CouponFactory $couponFactory,
        RuleCustomerFactory $ruleCustomerFactory,
        CouponUsageFactory $couponUsageFactory,
        CustomerSession $customerSession,
        ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->quoteRepository = $quoteRepository;
        $this->stockManagement = $stockManagement;
        $this->couponFactory = $couponFactory;
        $this->ruleCustomerFactory = $ruleCustomerFactory;
        $this->couponUsageFactory = $couponUsageFactory;
        $this->customerSession = $customerSession;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            // Check if auto-cancel feature is enabled
            if (!$this->scopeConfig->isSetFlag(
                'payment/payplus_gateway/orders_config/auto_cancel_pending_orders',
                ScopeInterface::SCOPE_STORE
            )) {
                return;
            }

            $quote = $observer->getEvent()->getQuote();

            if (!$quote || !$quote->getPayment()) {
                return;
            }

            $paymentMethod = $quote->getPayment()->getMethod();

            // Only handle PayPlus payments
            if (!$paymentMethod || strpos($paymentMethod, 'payplus_') !== 0) {
                return;
            }

            $customerId = $quote->getCustomerId();
            $customerEmail = $quote->getCustomerEmail();

            // Find pending PayPlus orders for this customer
            $pendingOrders = $this->findPendingPayPlusOrders($customerId, $customerEmail);

            if ($pendingOrders->getSize() > 0) {
                $this->logger->debugOrder('Found pending PayPlus orders to cancel', [
                    'customer_id' => $customerId,
                    'customer_email' => $customerEmail,
                    'pending_orders_count' => $pendingOrders->getSize()
                ]);

                foreach ($pendingOrders as $order) {
                    $this->cancelPendingOrder($order);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error in BeforePlaceOrder observer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Find pending PayPlus orders for the customer
     */
    private function findPendingPayPlusOrders($customerId, $customerEmail)
    {
        $collection = $this->orderCollectionFactory->create();

        // Add basic filters
        $collection->addFieldToFilter('state', ['in' => [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT]])
            ->addFieldToFilter('status', ['in' => ['pending', 'pending_payment']])
            ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d H:i:s', strtotime('-24 hours'))]);

        // Filter by customer
        if ($customerId) {
            $collection->addFieldToFilter('customer_id', $customerId);
        } else {
            $collection->addFieldToFilter('customer_email', $customerEmail);
        }

        // Join with payment table to filter PayPlus orders
        $collection->getSelect()
            ->join(
                ['payment' => $collection->getTable('sales_order_payment')],
                'main_table.entity_id = payment.parent_id',
                []
            )
            ->where('payment.method LIKE ?', 'payplus_%');

        return $collection;
    }

    /**
     * Cancel a pending order and restore stock/coupons
     */
    private function cancelPendingOrder(Order $order)
    {
        try {
            $this->logger->debugOrder('Canceling pending PayPlus order', [
                'order_id' => $order->getIncrementId(),
                'customer_id' => $order->getCustomerId(),
                'state' => $order->getState(),
                'status' => $order->getStatus()
            ]);

            // Cancel the order
            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Order automatically canceled due to new payment attempt');
                $order->save();

                $this->logger->debugOrder('Successfully canceled order', [
                    'order_id' => $order->getIncrementId()
                ]);
            } else {
                // For orders that can't be canceled normally, force cancel
                $order->setState(Order::STATE_CANCELED)
                    ->setStatus(Order::STATE_CANCELED)
                    ->addStatusHistoryComment('Order force-canceled due to new payment attempt');
                $order->save();

                // Manually restore stock
                $this->restoreStock($order);

                // Manually restore coupon usage
                $this->restoreCouponUsage($order);

                $this->logger->debugOrder('Force canceled order and restored stock/coupons', [
                    'order_id' => $order->getIncrementId()
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error canceling pending order', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Manually restore stock for order items
     */
    private function restoreStock(Order $order)
    {
        try {
            foreach ($order->getAllItems() as $item) {
                if ($item->getProductType() == 'simple' || $item->getProductType() == 'virtual') {
                    $this->stockManagement->backItemQty(
                        $item->getProductId(),
                        $item->getQtyOrdered(),
                        $order->getStore()->getWebsiteId()
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error restoring stock', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Manually restore coupon usage
     */
    private function restoreCouponUsage(Order $order)
    {
        try {
            $couponCode = $order->getCouponCode();
            if ($couponCode) {
                $coupon = $this->couponFactory->create();
                $coupon->load($couponCode, 'code');

                if ($coupon->getId()) {
                    // Restore coupon usage count
                    $coupon->setTimesUsed($coupon->getTimesUsed() - 1);
                    $coupon->save();

                    // Restore customer usage count
                    if ($order->getCustomerId()) {
                        $ruleCustomer = $this->ruleCustomerFactory->create();
                        $ruleCustomer->loadByCustomerRule(
                            $order->getCustomerId(),
                            $coupon->getRuleId()
                        );

                        if ($ruleCustomer->getId()) {
                            $ruleCustomer->setTimesUsed($ruleCustomer->getTimesUsed() - 1);
                            $ruleCustomer->save();
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error restoring coupon usage', [
                'order_id' => $order->getIncrementId(),
                'coupon_code' => $order->getCouponCode(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
