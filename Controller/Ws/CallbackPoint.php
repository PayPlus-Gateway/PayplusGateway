<?php

namespace Payplus\PayplusGateway\Controller\Ws;

class CallbackPoint extends \Payplus\PayplusGateway\Controller\Ws\ApiController
{
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\Webapi\Rest\Request $request,
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector,
        \Magento\Framework\Controller\ResultFactory $resultFactory
    ) {

        parent::__construct($request, $config, $apiConnector);
        $this->config = $config;
        $this->resultFactory = $resultFactory;
    }

    public function execute()
    {
        $responseRequest = $this->resultFactory->create('json');
        $params = $this->request->getBodyParams();
        $response = $this->apiConnector->checkTransactionAgainstIPN([
            'transaction_uid' =>  $params['transaction']['uid'],
            'payment_request_uid' =>  $params['transaction']['payment_page_request_uid']
        ]);

        if (!isset($response['data']) || $response['data']['status_code'] !== '000') {
            $responseRequest->setData(['status' => 'failure']);
            return $response;
        }
        $params = $response['data'];
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create(\Magento\Sales\Model\Order::class);
        $order = $collection->loadByIncrementId($params['more_info']);
        $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
        $orderResponse->processResponse($params);

        $responseRequest->setData(['status' => 'success']);
        return $responseRequest;
    }
}
