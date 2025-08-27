<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Payplus\PayplusGateway\Logger\Logger;

class CleanupAbandonedOrders implements ObserverInterface
{
    protected $orderCollectionFactory;
    protected $customerSession;
    protected $checkoutSession;
    protected $scopeConfig;
    protected $logger;

    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
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

            // Only run this for logged-in customers or guests with email
            $customerId = $this->customerSession->getCustomerId();
            $quote = $this->checkoutSession->getQuote();

            if (!$customerId && !($quote && $quote->getCustomerEmail())) {
                return;
            }

            $customerEmail = $customerId ? null : $quote->getCustomerEmail();

            // Find old pending PayPlus orders (older than 30 minutes)
            $oldPendingOrders = $this->findOldPendingPayPlusOrders($customerId, $customerEmail);

            if ($oldPendingOrders->getSize() > 0) {
                $this->logger->debugOrder('Cleaning up old pending PayPlus orders', [
                    'customer_id' => $customerId,
                    'customer_email' => $customerEmail,
                    'old_orders_count' => $oldPendingOrders->getSize()
                ]);

                foreach ($oldPendingOrders as $order) {
                    $this->cancelAbandonedOrder($order);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error in CleanupAbandonedOrders observer', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Find old pending PayPlus orders for the customer
     */
    private function findOldPendingPayPlusOrders($customerId, $customerEmail)
    {
        $collection = $this->orderCollectionFactory->create();

        // Add basic filters - orders older than 30 minutes but newer than 24 hours
        $collection->addFieldToFilter('state', ['in' => [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT]])
            ->addFieldToFilter('status', ['in' => ['pending', 'pending_payment']])
            ->addFieldToFilter('created_at', ['lt' => date('Y-m-d H:i:s', strtotime('-30 minutes'))])
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
     * Cancel an abandoned order
     */
    private function cancelAbandonedOrder(Order $order)
    {
        try {
            $this->logger->debugOrder('Canceling abandoned PayPlus order', [
                'order_id' => $order->getIncrementId(),
                'age_minutes' => round((time() - strtotime($order->getCreatedAt())) / 60)
            ]);

            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Order automatically canceled due to abandonment (no payment received within 30 minutes)');
                $order->save();
            } else {
                // Force cancel for orders that can't be canceled normally
                $order->setState(Order::STATE_CANCELED)
                    ->setStatus(Order::STATE_CANCELED)
                    ->addStatusHistoryComment('Order force-canceled due to abandonment');
                $order->save();
            }
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error canceling abandoned order', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
