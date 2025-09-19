<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\OrderService;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Checkout\Model\Session as CheckoutSession;

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
     * @var OrderService
     */
    protected $orderService;

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
        OrderService $orderService,
        LoggerInterface $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\Session $sessionManager
    ) {
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderService = $orderService;
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

            $this->logger->info('PayPlus Gateway Observer - TRIGGERED - Page accessed: ' . $currentPage, [
                'event_name' => $observer->getEvent()->getName(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            // Check if order cancellation is enabled
            if (!$this->isOrderCancellationEnabled()) {
                $this->logger->info('PayPlus Gateway Observer - Order cancellation disabled in configuration');
                return;
            }

            $this->logger->info('PayPlus Gateway Observer - Order cancellation is ENABLED, proceeding...');

            // Get current customer identifier (ID for logged in, email for guest)
            $customerIdentifier = $this->getCurrentCustomerIdentifier();
            if (!$customerIdentifier) {
                $this->logger->info('PayPlus Gateway Observer - No customer identifier found, skipping order processing');
                return;
            }

            $this->logger->info('PayPlus Gateway Observer - Customer identifier found, processing orders...', [
                'customer_type' => $customerIdentifier['type'],
                'customer_value' => $customerIdentifier['value']
            ]);

            // Process orders with duplicate prevention
            $this->processPendingOrders($customerIdentifier, $currentPage);
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error in CheckoutPredispatch observer: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
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
        } elseif ($eventName === 'controller_action_predispatch_onestepcheckout_index_index') {
            return 'onestep_checkout';
        } elseif ($eventName === 'sales_quote_save_before') {
            return 'quote_save';
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
            'payment/payplus_gateway/orders_config/cancel_and_restore_orders',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Process pending orders for the current customer
     *
     * @param array $customerIdentifier
     * @param string $currentPage
     * @return void
     */
    protected function processPendingOrders($customerIdentifier, $currentPage = 'unknown')
    {
        // Get current quote to check if there's an active reserved order
        $currentQuote = $this->checkoutSession->getQuote();
        $currentReservedOrderId = $currentQuote ? $currentQuote->getReservedOrderId() : null;

        $this->logger->info('PayPlus Gateway - Processing orders', [
            'customer_type' => $customerIdentifier['type'],
            'customer_identifier' => $customerIdentifier['value'],
            'current_page' => $currentPage,
            'current_reserved_order_id' => $currentReservedOrderId,
            'quote_id' => $currentQuote ? $currentQuote->getId() : null
        ]);

        // Get today's date range in store timezone (extended to include yesterday to catch edge cases)
        $yesterdayStart = $this->timezone->date()->modify('-1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $this->timezone->date()->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        // Find all pending orders from yesterday and today for this customer
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
            ->addFieldToFilter('created_at', ['gteq' => $yesterdayStart])
            ->addFieldToFilter('created_at', ['lteq' => $todayEnd])
            ->setOrder('created_at', 'DESC'); // Most recent first

        $ordersFound = $orderCollection->getSize();
        $ordersCancelled = 0;

        $this->logger->info('PayPlus Gateway - Found pending orders', [
            'total_found' => $ordersFound,
            'customer_type' => $customerIdentifier['type']
        ]);

        foreach ($orderCollection as $order) {
            $orderId = $order->getId();
            $orderIncrementId = $order->getIncrementId();
            $orderCreatedAt = new \DateTime($order->getCreatedAt());
            $now = new \DateTime();
            $minutesSinceCreated = $now->diff($orderCreatedAt)->i + ($now->diff($orderCreatedAt)->h * 60);

            // If this order matches the current quote's reserved order ID, only skip it if it's very recent (less than 5 minutes old)
            // This prevents keeping old orders that match the current reserved ID but are from previous sessions
            if ($currentReservedOrderId && $orderIncrementId === $currentReservedOrderId && $minutesSinceCreated < 5) {
                $this->logger->info('PayPlus Gateway - Skipping current active recent order: ' . $orderId . ' (' . $orderIncrementId . ')', [
                    'minutes_old' => $minutesSinceCreated
                ]);
                continue;
            }

            $couponCode = $order->getCouponCode();
            $hasCoupon = !empty($couponCode);

            $this->logger->info('PayPlus Gateway - Processing order for cancellation', [
                'order_id' => $orderId,
                'order_increment_id' => $orderIncrementId,
                'has_coupon' => $hasCoupon,
                'coupon_code' => $couponCode,
                'page' => $currentPage,
                'created_at' => $order->getCreatedAt(),
                'minutes_old' => $minutesSinceCreated,
                'is_current_reserved' => ($currentReservedOrderId && $orderIncrementId === $currentReservedOrderId)
            ]);

            // Cancel order using Magento's built-in service (handles coupon and stock restoration)
            $this->cancelOrder($order);
            $ordersCancelled++;
        }

        $this->logger->info('PayPlus Gateway - Completed order processing', [
            'orders_found' => $ordersFound,
            'orders_cancelled' => $ordersCancelled,
            'page' => $currentPage
        ]);
    }

    /**
     * Cancel the order using Magento's OrderService
     *
     * @param Order $order
     * @return void
     */
    protected function cancelOrder($order)
    {
        try {
            if ($order->canCancel()) {
                $orderId = $order->getId();
                // Cancel the order
                $this->orderService->cancel($orderId);

                // Reload the order to get the updated state
                $order = $this->orderCollectionFactory->create()
                    ->addFieldToFilter('entity_id', $orderId)
                    ->getFirstItem();

                if ($order && $order->isCanceled()) {
                    $couponCode = $order->getCouponCode();
                    $hasCoupon = !empty($couponCode);

                    $comment = $hasCoupon
                        ? 'Order cancelled automatically due to new checkout session. Coupon usage and stock have been restored.'
                        : 'Order cancelled automatically due to new checkout session. Stock has been restored.';

                    $order->addCommentToStatusHistory($comment, false, false);
                    $order->save();

                    $this->logger->info('PayPlus Gateway - Cancelled pending order using OrderService', [
                        'order_id' => $orderId,
                        'coupon_code' => $couponCode,
                        'customer_id' => $order->getCustomerId(),
                        'had_coupon' => $hasCoupon
                    ]);
                } else {
                    $this->logger->warning('PayPlus Gateway - OrderService did not cancel the order as expected', [
                        'order_id' => $orderId
                    ]);
                }
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
