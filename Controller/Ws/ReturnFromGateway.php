<?php

namespace Payplus\PayplusGateway\Controller\Ws;

use Magento\Sales\Model\Order;

class ReturnFromGateway extends \Payplus\PayplusGateway\Controller\Ws\ApiController
{
    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $resultFactory;

    protected $transactionsRepository;

    public $config;

    public $resultJsonFactory;

    public $apiConnector;

    public $request;

    public $_logger;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\Webapi\Rest\Request $request,
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Payplus\PayplusGateway\Logger\Logger $logger
    ) {

        parent::__construct($request, $config, $apiConnector);
        $this->config = $config;
        $this->resultFactory = $resultFactory;
        $this->_logger = $logger;
    }

    public function execute()
    {
        /**
         * @var \Magento\Framework\Controller\Result\Redirect\Interceptor
         */

        $resultRedirect = $this->resultFactory->create('redirect');
        $params = $this->request->getParams();
        $this->_logger->debugOrder('params response get', $params);
        $response = $this->apiConnector->checkTransactionAgainstIPN([
            'transaction_uid' => $params['transaction_uid'],
            'payment_request_uid' => $params['page_request_uid']
        ]);
        $this->_logger->debugOrder('ipn  payplus', $response);
        if (!isset($response['data']) || $response['data']['status_code'] !== '000') {
            $resultRedirect->setPath('checkout/onepage/failure');
            return $resultRedirect;
        }

        $params = $response['data'];
        $status = true;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create(\Magento\Sales\Model\Order::class);
        $order = $collection->loadByIncrementId($params['more_info']);
        $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
        $status = $orderResponse->processResponse($params);

        /*  if ($this->config->getValue(
            'payment/payplus_gateway/payment_page/use_callback',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) == 0) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $collection = $objectManager->create(\Magento\Sales\Model\Order::class);
            $order = $collection->loadByIncrementId($params['more_info']);
            $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
            $status = $orderResponse->processResponse($params);
        } else {
            $status = true;
        }
      */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $cartObject = $objectManager->create(\Magento\Checkout\Model\Cart::class)->truncate();
        $cartObject->saveQuote();

        if ($response['results']['status'] != 'success' || $status === false) {
            $resultRedirect->setPath('checkout/onepage/failure');
        } else {
            $type = $response['data']['type'];


            if ($type == "Charge") {
                $statusOrderPayplus = $this->config->getValue(
                    'payment/payplus_gateway/api_configuration/status_order_payplus',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );

                $stateOrderPayplus = $this->config->getValue(
                    'payment/payplus_gateway/api_configuration/state_order_payplus',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );

                $statusApprovalOrderPayplus = $this->config->getValue(
                    'payment/payplus_gateway/api_configuration/status_approval_order_payplus',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );

                $stateApprovalOrderPayplus = $this->config->getValue(
                    'payment/payplus_gateway/api_configuration/state_approval_order_payplus',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );

                $stateOrderPayplus = ($stateOrderPayplus) ? $stateOrderPayplus : 'complete';
                if ($statusOrderPayplus) {

                    $order = $objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId($params['more_info']);
                    $order->addStatusHistoryComment($statusOrderPayplus . " order id :" . $params['more_info']);
                    $order->setState($stateOrderPayplus)->setStatus($statusOrderPayplus);
                    $order->save();
                } else {
                    $statusOrder = Order::STATE_COMPLETE;
                    $order = $objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId($params['more_info']);
                    $order->setState($stateOrderPayplus)->setStatus($statusOrder);
                    $order->save();
                }
            }
            $resultRedirect->setPath('checkout/onepage/success');
        }
        return $resultRedirect;
    }
}
