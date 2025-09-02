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

        // Check if this is a multipass transaction
        $isMultipass = isset($params['method']) && $params['method'] === 'multipass';
        $isMultipleTransaction = isset($params['is_multiple_transaction']) &&
            ($params['is_multiple_transaction'] === 'true' || $params['is_multiple_transaction'] === true);

        if ($isMultipass || $isMultipleTransaction) {
            $this->_logger->debugOrder('Detected multipass transaction', [
                'method' => $params['method'] ?? 'not_set',
                'is_multiple_transaction' => $params['is_multiple_transaction'] ?? 'not_set',
                'transaction_uid' => $params['transaction_uid'] ?? 'not_set'
            ]);
        }

        try {
            $response = $this->apiConnector->checkTransactionAgainstIPN([
                'transaction_uid' => $params['transaction_uid'],
                'payment_request_uid' => $params['page_request_uid']
            ]);
            $this->_logger->debugOrder('ipn  payplus', $response);
        } catch (\Exception $e) {
            $this->_logger->debugOrder('IPN check failed', [
                'error' => $e->getMessage(),
                'transaction_uid' => $params['transaction_uid'] ?? 'not_set'
            ]);
            $resultRedirect->setPath('checkout/onepage/failure');
            return $resultRedirect;
        }

        if (!isset($response['data']) || $response['data']['status_code'] !== '000') {
            $this->_logger->debugOrder('IPN response invalid or failed', [
                'has_data' => isset($response['data']),
                'status_code' => $response['data']['status_code'] ?? 'not_set'
            ]);
            $resultRedirect->setPath('checkout/onepage/failure');
            return $resultRedirect;
        }

        $params = $response['data'];
        $status = true;

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $collection = $objectManager->create(\Magento\Sales\Model\Order::class);
            $order = $collection->loadByIncrementId($params['more_info']);

            if (!$order->getId()) {
                $this->_logger->debugOrder('Order not found', [
                    'order_increment_id' => $params['more_info'] ?? 'not_set'
                ]);
                $resultRedirect->setPath('checkout/onepage/failure');
                return $resultRedirect;
            }

            $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
            $status = $orderResponse->processResponse($params);

            // Add payment response to order notes
            try {
                // Add formatted multipass transaction details if this is a multipass transaction
                if (
                    isset($params['method']) && $params['method'] === 'multipass' &&
                    isset($params['related_transactions']) && is_array($params['related_transactions'])
                ) {

                    $multipassComment = "=== MULTIPASS TRANSACTION BREAKDOWN ===\n";
                    $multipassComment .= "Main Transaction ID: {$params['transaction_uid']}\n";
                    $multipassComment .= "Total Amount: {$params['amount']} {$params['currency']}\n";
                    $multipassComment .= "Transaction Status: {$params['status']}\n\n";
                    $multipassComment .= "Payment Methods Used:\n";
                    $multipassComment .= "----------------------------------------\n";

                    foreach ($params['related_transactions'] as $index => $txn) {
                        $multipassComment .= ($index + 1) . ". ";

                        if ($txn['method'] === 'credit-card') {
                            $multipassComment .= "CREDIT CARD PAYMENT\n";
                            $brandInfo = isset($txn['brand_code']) ? "{$txn['brand_name']} - {$txn['brand_code']}" : $txn['brand_name'];
                            $multipassComment .= "   Card: ****{$txn['four_digits']} ({$brandInfo})\n";
                            $multipassComment .= "   Cardholder: {$txn['card_holder_name']}\n";
                            $multipassComment .= "   Amount: {$txn['amount']} {$txn['currency']}\n";
                            $multipassComment .= "   Approval: {$txn['approval_num']}\n";
                            $multipassComment .= "   Voucher: {$txn['voucher_num']}\n";
                            $multipassComment .= "   Transaction ID: {$txn['transaction_uid']}\n";
                        } else {
                            $multipassComment .= "MULTIPASS WALLET PAYMENT\n";
                            $methodInfo = isset($txn['brand_code']) ? "{$txn['alternative_method_name']} - Brand Code:{$txn['brand_code']}" : $txn['alternative_method_name'];
                            $multipassComment .= "   Method: {$methodInfo}\n";
                            $multipassComment .= "   Voucher: {$txn['voucher_num']}\n";
                            $multipassComment .= "   Amount: {$txn['amount']} {$txn['currency']}\n";
                            $multipassComment .= "   Approval: {$txn['approval_num']}\n";
                            $multipassComment .= "   Transaction ID: {$txn['transaction_uid']}\n";
                        }

                        $multipassComment .= "   Status: {$txn['status']}\n";
                        $multipassComment .= "   Date: {$txn['date']}\n\n";
                    }

                    $order->addStatusHistoryComment($multipassComment);
                }

                $order->save();
            } catch (\Exception $e) {
                $this->_logger->debugOrder('Error adding payment response to notes', ['error' => $e->getMessage()]);
            }
        } catch (\Exception $e) {
            $this->_logger->debugOrder('Error processing order response', [
                'error' => $e->getMessage(),
                'order_increment_id' => $params['more_info'] ?? 'not_set'
            ]);
            $resultRedirect->setPath('checkout/onepage/failure');
            return $resultRedirect;
        }

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
