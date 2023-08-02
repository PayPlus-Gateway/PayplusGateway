<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;

class AfterPlaceOrder implements ObserverInterface
{
    protected $apiConnector;

    public function __construct(
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector
    ) {
        $this->apiConnector = $apiConnector;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {

        $order = $observer->getEvent()->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $order->setEmailSent(false);
        $order->setSendEmail(false);
        $payment = $order->getPayment();
        $transactionID = $payment->getAdditionalInformation('transaction_uid');

        if ($transactionID && $order) {
            $response = $this->apiConnector->checkTransactionAgainstIPN([
                'transaction_uid' => $transactionID
            ]);
            $params = $response['data'];
               $orderResponse = new \Payplus\PayplusGateway\Model\Custom\OrderResponse($order);
               $orderResponse->processResponse($params, true);
        }
    }
}
