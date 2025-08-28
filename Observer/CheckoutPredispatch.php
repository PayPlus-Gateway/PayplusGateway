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
     * @param bool $hasCoupon
     * @param bool $couponWasRestored
     * @return void
     */
    protected function cancelOrder($order, $hasCoupon = false, $couponWasRestored = false)
    {
        try {
            if ($order->canCancel()) {
                $order->cancel();

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
