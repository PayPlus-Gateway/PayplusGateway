<?php

namespace Payplus\PayplusGateway\Controller\Ws;

use Magento\Framework\Exception\LocalizedException;
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

        // Handle case where params array is completely empty (PayPlus API issue)
        // Verify payment status via IPN before sending to success page
        // Only apply this fix if enabled in plugin settings
        $enableIpnVerificationEmptyParams = $this->config->isSetFlag(
            'payment/payplus_gateway/orders_config/enable_ipn_verification_empty_params',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if (empty($params) || count($params) === 0) {
            if ($enableIpnVerificationEmptyParams) {
                // Apply fix: verify payment via IPN
                $this->_logger->debugOrder('Return params are completely empty - PayPlus API issue, verifying payment via IPN', [
                    'params' => $params
                ]);

                try {
                    // Get the last order from checkout session
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $checkoutSession = $objectManager->create(\Magento\Checkout\Model\Session::class);
                    $lastOrderId = $checkoutSession->getLastOrderId();

                    if (!$lastOrderId) {
                        $this->_logger->debugOrder('Empty params and no last order ID found in session - sending to failure', []);
                        $resultRedirect->setPath('checkout/onepage/failure');
                        return $resultRedirect;
                    }

                    $order = $objectManager->create(\Magento\Sales\Model\Order::class)->load($lastOrderId);

                    if (!$order->getId()) {
                        $this->_logger->debugOrder('Empty params and order not found - sending to failure', [
                            'order_id' => $lastOrderId
                        ]);
                        $resultRedirect->setPath('checkout/onepage/failure');
                        return $resultRedirect;
                    }

                    // Get page_request_uid from order payment (same as order syncer)
                    $payment = $order->getPayment();
                    $paymentData = $payment->getData();
                    $paymentRequestUid = $paymentData['additional_data'] ?? null;

                    // Fallback: try to get from paymentPageResponse
                    if (!$paymentRequestUid) {
                        $additionalInfo = $payment->getAdditionalInformation();
                        if (isset($additionalInfo['paymentPageResponse']['page_request_uid'])) {
                            $paymentRequestUid = $additionalInfo['paymentPageResponse']['page_request_uid'];
                        }
                    }

                    if (!$paymentRequestUid) {
                        $this->_logger->debugOrder('Empty params and no page_request_uid found in order - sending to failure', [
                            'order_id' => $order->getIncrementId()
                        ]);
                        $resultRedirect->setPath('checkout/onepage/failure');
                        return $resultRedirect;
                    }

                    // Verify payment status via IPN (same as order syncer)
                    $response = $this->apiConnector->checkTransactionAgainstIPN([
                        'payment_request_uid' => $paymentRequestUid
                    ]);

                    // Check if payment succeeded (same validation as order syncer)
                    if (
                        isset($response['results']['status']) &&
                        $response['results']['status'] === 'success' &&
                        isset($response['results']['code']) &&
                        $response['results']['code'] == 0 &&
                        isset($response['data']) &&
                        isset($response['data']['status_code']) &&
                        $response['data']['status_code'] === '000'
                    ) {
                        // Validate that IPN response matches this order (same as order syncer)
                        $ipnMoreInfo = $response['data']['more_info'] ?? null;
                        $orderIncrementId = $order->getIncrementId();

                        if ($ipnMoreInfo !== $orderIncrementId) {
                            $this->_logger->debugOrder('Empty params: IPN response more_info does not match order ID - sending to failure', [
                                'order_id' => $orderIncrementId,
                                'ipn_more_info' => $ipnMoreInfo,
                                'page_request_uid' => $paymentRequestUid
                            ]);
                            $resultRedirect->setPath('checkout/onepage/failure');
                            return $resultRedirect;
                        }

                        // Validate amount matches (same as order syncer)
                        $ipnAmount = isset($response['data']['amount']) ? (float)$response['data']['amount'] : null;
                        $orderAmount = (float)$order->getGrandTotal();
                        $amountDifference = abs($ipnAmount - $orderAmount);

                        if ($ipnAmount === null || $amountDifference > 0.01) {
                            $this->_logger->debugOrder('Empty params: IPN response amount does not match order amount - sending to failure', [
                                'order_id' => $orderIncrementId,
                                'ipn_amount' => $ipnAmount,
                                'order_amount' => $orderAmount,
                                'difference' => $amountDifference
                            ]);
                            $resultRedirect->setPath('checkout/onepage/failure');
                            return $resultRedirect;
                        }

                        // Process the order (same as order syncer) - this sets status according to plugin settings
                        $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
                        $orderResponse->processResponse($response['data'], true);

                        $this->_logger->debugOrder('Empty params but IPN verification successful - order processed and sending to success page', [
                            'order_id' => $order->getIncrementId(),
                            'page_request_uid' => $paymentRequestUid,
                            'status_code' => $response['data']['status_code'],
                            'transaction_type' => $response['data']['type'] ?? 'not_set'
                        ]);

                        // Clear cart and send to success
                        $cartObject = $objectManager->create(\Magento\Checkout\Model\Cart::class);
                        $cartObject->getQuote()->setIsActive(false);
                        $cartObject->saveQuote();

                        $resultRedirect->setPath('checkout/onepage/success');
                        return $resultRedirect;
                    } else {
                        $this->_logger->debugOrder('Empty params and IPN verification failed - sending to failure', [
                            'order_id' => $order->getIncrementId(),
                            'page_request_uid' => $paymentRequestUid,
                            'response_status' => $response['results']['status'] ?? 'not_set',
                            'status_code' => $response['data']['status_code'] ?? 'not_set'
                        ]);
                        $resultRedirect->setPath('checkout/onepage/failure');
                        return $resultRedirect;
                    }
                } catch (\Exception $e) {
                    $this->_logger->debugOrder('Empty params and error during IPN verification - sending to failure', [
                        'error' => $e->getMessage()
                    ]);
                    $resultRedirect->setPath('checkout/onepage/failure');
                    return $resultRedirect;
                }
            } else {
                // Fix disabled: send to failure when params are empty (old behavior)
                $this->_logger->debugOrder('Return params are completely empty and payment fixes are disabled - sending to failure', [
                    'params' => $params
                ]);
                $resultRedirect->setPath('checkout/onepage/failure');
                return $resultRedirect;
            }
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
            $status = $orderResponse->processResponse($params, true);

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
        $cartObject = $objectManager->create(\Magento\Checkout\Model\Cart::class);
        $cartObject->getQuote()->setIsActive(false);
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
