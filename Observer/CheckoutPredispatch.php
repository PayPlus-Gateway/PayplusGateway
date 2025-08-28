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
            // Check if the feature is enabled in configuration
            if (!$this->isFeatureEnabled()) {
                return;
            }

            // Only process for logged-in customers
            if (!$this->customerSession->isLoggedIn()) {
                return;
            }

            $customerId = $this->customerSession->getCustomerId();
            $this->processPendingOrdersWithCoupons($customerId);
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error in CheckoutPredispatch observer: ' . $e->getMessage());
        }
    }

    /**
     * Check if the pending order cancellation feature is enabled
     *
     * @return bool
     */
    protected function isFeatureEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/orders_config/cancel_pending_orders_with_coupons',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Process pending orders with coupons for the current customer
     *
     * @param int $customerId
     * @return void
     */
    protected function processPendingOrdersWithCoupons($customerId)
    {
        // Get today's date range in store timezone
        $todayStart = $this->timezone->date()->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $this->timezone->date()->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        // Find pending orders from today for this customer
        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('status', 'pending_payment')
            ->addFieldToFilter('created_at', ['gteq' => $todayStart])
            ->addFieldToFilter('created_at', ['lteq' => $todayEnd])
            ->addFieldToFilter('coupon_code', ['notnull' => true])
            ->addFieldToFilter('coupon_code', ['neq' => '']);

        foreach ($orderCollection as $order) {
            $couponCode = $order->getCouponCode();
            if ($couponCode) {
                $this->restoreCouponUsage($couponCode, $order);
                $this->cancelOrder($order);
            }
        }
    }

    /**
     * Restore coupon usage by decrementing the times_used counter
     *
     * @param string $couponCode
     * @param Order $order
     * @return void
     */
    protected function restoreCouponUsage($couponCode, $order)
    {
        try {
            $couponCollection = $this->couponCollectionFactory->create();
            $couponCollection->addFieldToFilter('code', $couponCode);

            $coupon = $couponCollection->getFirstItem();
            if ($coupon && $coupon->getId()) {
                $currentUsage = $coupon->getTimesUsed();
                if ($currentUsage > 0) {
                    $coupon->setTimesUsed($currentUsage - 1);
                    $coupon->save();

                    $this->logger->info(
                        'PayPlus Gateway - Restored coupon usage',
                        [
                            'coupon_code' => $couponCode,
                            'order_id' => $order->getId(),
                            'previous_usage' => $currentUsage,
                            'new_usage' => $currentUsage - 1
                        ]
                    );
                }
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
     * Cancel the order
     *
     * @param Order $order
     * @return void
     */
    protected function cancelOrder($order)
    {
        try {
            if ($order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory(
                    'Order cancelled automatically due to new checkout session with same coupon.',
                    false,
                    false
                );
                $order->save();

                $this->logger->info(
                    'PayPlus Gateway - Cancelled pending order with coupon',
                    [
                        'order_id' => $order->getId(),
                        'coupon_code' => $order->getCouponCode(),
                        'customer_id' => $order->getCustomerId()
                    ]
                );
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
