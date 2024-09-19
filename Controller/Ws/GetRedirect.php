<?php

namespace Payplus\PayplusGateway\Controller\Ws;

class GetRedirect implements \Magento\Framework\App\ActionInterface
{
    protected $resultJsonFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Payment\Interceptor
     */
    protected $paymentMethod;
    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\Repository
     */

    protected $transactionsRepository;
    public $orderRepository;
    public $request;
    public $apiConnector;
    public function __construct(
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\App\RequestInterface $request,
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->request = $request;
        $this->apiConnector = $apiConnector;
    }

    public function execute()
    {
        $params = $this->request->getParams();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $_checkoutSession = $objectManager->create(\Magento\Checkout\Model\Session::class);
        $_quoteFactory = $objectManager->create(\Magento\Quote\Model\QuoteFactory::class);
        $order = $this->orderRepository->get($params['orderid']);
        $quote = $_quoteFactory->create()->loadByIdWithoutStore($order->getQuoteId());
        if ($quote->getId()) {
            $quote->setIsActive(1)->setReservedOrderId(null)->save();
            $_checkoutSession->replaceQuote($quote);
        }
        $result = $this->resultJsonFactory->create();
        $payment = $order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();
        $paymentData = $payment->getData();
        $resultData = [];
        if ($paymentData['additional_data'] && $additionalInformation['awaiting_payment']) {
            $additionalInformation['awaiting_payment'] = false;
            $payment->setAdditionalInformation($additionalInformation);
            $resultData['status'] = 'success';
            $resultData['redirectUrl'] = $this->apiConnector->getPaymentAddress(). '/' .$paymentData['additional_data'];
        } else {
            $resultData['status'] = 'failure';
        }
        
        $payment->setIsTransactionClosed(false);
        
        $order->setStatus('pending_payment');
        $order->setState('pending_payment');
        $order->save();
        return $result->setData($resultData);
    }
}
