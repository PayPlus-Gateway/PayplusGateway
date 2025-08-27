<?php

namespace Payplus\PayplusGateway\Controller\Ws;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Payplus\PayplusGateway\Logger\Logger;

class CleanupPendingOrders implements HttpPostActionInterface
{
    protected $request;
    protected $resultJsonFactory;
    protected $orderCollectionFactory;
    protected $customerSession;
    protected $checkoutSession;
    protected $scopeConfig;
    protected $logger;

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            // Check if auto-cancel feature is enabled
            if (!$this->scopeConfig->isSetFlag(
                'payment/payplus_gateway/orders_config/auto_cancel_pending_orders',
                ScopeInterface::SCOPE_STORE
            )) {
                return $result->setData([
                    'success' => true,
                    'canceled_orders' => 0,
                    'message' => 'Auto-cancel feature is disabled'
                ]);
            }

            $customerId = $this->customerSession->getCustomerId();
            $quote = $this->checkoutSession->getQuote();
            $customerEmail = $customerId ? null : ($quote ? $quote->getCustomerEmail() : null);

            if (!$customerId && !$customerEmail) {
                return $result->setData([
                    'success' => false,
                    'message' => 'No customer identified'
                ]);
            }

            $canceledCount = $this->cancelPendingOrders($customerId, $customerEmail);

            $this->logger->debugOrder('AJAX cleanup completed', [
                'customer_id' => $customerId,
                'customer_email' => $customerEmail,
                'canceled_count' => $canceledCount
            ]);

            return $result->setData([
                'success' => true,
                'canceled_orders' => $canceledCount,
                'message' => "Canceled {$canceledCount} pending orders"
            ]);
        } catch (\Exception $e) {
            $this->logger->debugOrder('Error in cleanup controller', [
                'error' => $e->getMessage()
            ]);

            return $result->setData([
                'success' => false,
                'message' => 'Error during cleanup: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Cancel pending PayPlus orders for the customer
     */
    private function cancelPendingOrders($customerId, $customerEmail)
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

        $canceledCount = 0;
        foreach ($collection as $order) {
            try {
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addStatusHistoryComment('Order canceled before new payment attempt');
                    $order->save();
                } else {
                    $order->setState(Order::STATE_CANCELED)
                        ->setStatus(Order::STATE_CANCELED)
                        ->addStatusHistoryComment('Order force-canceled before new payment attempt');
                    $order->save();
                }
                $canceledCount++;
            } catch (\Exception $e) {
                $this->logger->debugOrder('Error canceling order in cleanup controller', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $canceledCount;
    }
}
