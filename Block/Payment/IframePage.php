<?php

namespace Payplus\PayplusGateway\Block\Payment;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\UrlInterface;

class IframePage extends Template
{
    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var int
     */
    protected $orderId;

    /**
     * Constructor
     *
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param UrlInterface $urlBuilder
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        UrlInterface $urlBuilder,
        array $data = []
    ) {
        $this->orderFactory = $orderFactory;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $data);
    }

    /**
     * Set order ID
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;
        return $this;
    }

    /**
     * Get order ID
     *
     * @return int
     */
    public function getOrderId()
    {
        if (!$this->orderId) {
            $this->orderId = $this->getRequest()->getParam('order_id');
        }
        return $this->orderId;
    }

    /**
     * Get iframe height from configuration
     *
     * @return int
     */
    public function getIframeHeight()
    {
        return (int) $this->scopeConfig->getValue(
            'payment/payplus_gateway/display_settings/iframe_height',
            ScopeInterface::SCOPE_STORE
        ) ?: 700;
    }

    /**
     * Get PayPlus redirect URL for the order
     *
     * @return string|null
     */
    public function getPaymentUrl()
    {
        try {
            $orderId = $this->getOrderId();
            if (!$orderId) {
                return null;
            }

            // This will be populated by JavaScript after calling the getredirect endpoint
            return $this->urlBuilder->getUrl('payplus_gateway/ws/getredirect', ['orderid' => $orderId]);
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error getting payment URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get success return URL
     *
     * @return string
     */
    public function getSuccessUrl()
    {
        return $this->urlBuilder->getUrl('checkout/onepage/success');
    }

    /**
     * Get failure return URL
     *
     * @return string
     */
    public function getFailureUrl()
    {
        return $this->urlBuilder->getUrl('checkout/onepage/failure');
    }

    /**
     * Get cancel return URL
     *
     * @return string
     */
    public function getCancelUrl()
    {
        return $this->urlBuilder->getUrl('checkout/cart');
    }

    /**
     * Check if order exists and is valid
     *
     * @return bool
     */
    public function isValidOrder()
    {
        try {
            $orderId = $this->getOrderId();
            if (!$orderId) {
                $this->logger->error('PayPlus Gateway - No order ID provided');
                return false;
            }

            $order = $this->orderFactory->create()->load($orderId);
            if (!$order->getId()) {
                $this->logger->error('PayPlus Gateway - Order not found: ' . $orderId);
                return false;
            }

            $this->logger->info('PayPlus Gateway - Order validation', [
                'order_id' => $orderId,
                'order_state' => $order->getState(),
                'order_status' => $order->getStatus(),
                'payment_method' => $order->getPayment()->getMethod()
            ]);

            // Allow orders in pending payment state or new state (for PayPlus orders)
            $validStates = [
                \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
                \Magento\Sales\Model\Order::STATE_NEW,
                \Magento\Sales\Model\Order::STATE_PROCESSING
            ];

            $isValidState = in_array($order->getState(), $validStates);
            $isPayPlusOrder = strpos($order->getPayment()->getMethod(), 'payplus') !== false;

            return $order->getId() && $isValidState && $isPayPlusOrder;
        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error validating order: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get loading message
     *
     * @return string
     */
    public function getLoadingMessage()
    {
        return __('Loading payment form...');
    }

    /**
     * Get error message
     *
     * @return string
     */
    public function getErrorMessage()
    {
        return __('Unable to load payment form. Please try again or contact support.');
    }
}
