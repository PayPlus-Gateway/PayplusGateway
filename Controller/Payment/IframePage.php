<?php

namespace Payplus\PayplusGateway\Controller\Payment;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class IframePage implements ActionInterface
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Constructor
     *
     * @param PageFactory $resultPageFactory
     * @param RedirectFactory $resultRedirectFactory
     * @param RequestInterface $request
     * @param OrderFactory $orderFactory
     * @param ManagerInterface $messageManager
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        PageFactory $resultPageFactory,
        RedirectFactory $resultRedirectFactory,
        RequestInterface $request,
        OrderFactory $orderFactory,
        ManagerInterface $messageManager,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->request = $request;
        $this->orderFactory = $orderFactory;
        $this->messageManager = $messageManager;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $orderId = $this->request->getParam('order_id');
            
            $this->logger->info('PayPlus Gateway - IframePage accessed', [
                'order_id_param' => $orderId,
                'all_params' => $this->request->getParams()
            ]);
            
            if (!$orderId) {
                $this->logger->error('PayPlus Gateway - No order ID provided in iframe page request');
                $this->messageManager->addErrorMessage(__('Invalid order ID.'));
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('checkout/cart');
            }

            // Validate order exists and belongs to current customer/session
            $order = $this->orderFactory->create()->load($orderId);
            
            if (!$order->getId()) {
                $this->logger->error('PayPlus Gateway - Order not found in iframe page: ' . $orderId);
                $this->messageManager->addErrorMessage(__('Order not found.'));
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('checkout/cart');
            }

            $this->logger->info('PayPlus Gateway - Order found in iframe page', [
                'order_id' => $orderId,
                'order_state' => $order->getState(),
                'order_status' => $order->getStatus(),
                'payment_method' => $order->getPayment()->getMethod()
            ]);

            // Check if iframe_page mode is enabled
            $formType = $this->scopeConfig->getValue(
                'payment/payplus_gateway/display_settings/iframe_or_redirect',
                ScopeInterface::SCOPE_STORE
            );

            if ($formType !== 'iframe_page') {
                $this->logger->error('PayPlus Gateway - Iframe page mode not enabled: ' . $formType);
                $this->messageManager->addErrorMessage(__('Iframe page mode is not enabled.'));
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('checkout/cart');
            }

            // Create the page
            $resultPage = $this->resultPageFactory->create();
            $resultPage->getConfig()->getTitle()->set(__('Complete Your Payment'));
            
            // Pass order ID to the block via registry or layout
            $resultPage->getLayout()->getBlock('payplus.iframe.page');

            return $resultPage;

        } catch (\Exception $e) {
            $this->logger->error('PayPlus Gateway - Error loading iframe page: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while loading the payment page.'));
            
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('checkout/cart');
        }
    }
}
