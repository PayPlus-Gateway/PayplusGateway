<?php

namespace Payplus\PayplusGateway\Controller\Ws;

class CallbackPoint extends \Payplus\PayplusGateway\Controller\Ws\ApiController
{
    public $resultFactory;
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
        $this->_logger=$logger;
    }

    public function execute()
    {
        $responseRequest = $this->resultFactory->create('json');
        $params = $this->request->getBodyParams();
        $this->_logger->debugOrder('callback  payplus',$params);

        if (!isset( $params['transaction']) || !is_array ($params['transaction'])) {
            throw new \InvalidArgumentException('Response does not exist');
        }
        $response = $this->apiConnector->checkTransactionAgainstIPN([
            'transaction_uid' =>  $params['transaction']['uid'],
            'payment_request_uid' =>  $params['transaction']['payment_page_request_uid']
        ]);

        if (!isset($response['data']) || $response['data']['status_code'] !== '000') {
            $responseRequest->setData(['status' => 'failure']);
            return $response;
        }
        $params = $response['data'];
        $this->_logger->debugOrder('callback ipn payplus',$params);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create(\Magento\Sales\Model\Order::class);
        $order = $collection->loadByIncrementId($params['more_info']);

        $enableFraudPrevention = $this->config->isSetFlag(
            'payment/payplus_gateway/orders_config/enable_fraud_prevention',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($enableFraudPrevention) {
            if (!$order->getId()) {
                $this->_logger->debugOrder('Callback: order not found', [
                    'more_info' => $params['more_info'] ?? 'not_set'
                ]);
                $responseRequest->setData(['status' => 'failure']);
                return $responseRequest;
            }

            $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
            $orderResponse->processResponse($params, true);
        } else {
            $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
            $orderResponse->processResponse($params);
        }

        $responseRequest->setData(['status' => 'success']);
        return $responseRequest;
    }
}
