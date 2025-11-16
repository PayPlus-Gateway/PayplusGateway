<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;

class AfterPlaceOrder implements ObserverInterface
{
    protected $apiConnector;
    protected $scopeConfig;

    public function __construct(
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->apiConnector = $apiConnector;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {

        $order = $observer->getEvent()->getOrder();
        $storeId = $order->getStoreId();

        // Check if Payplus is enabled for this store
        if (!$this->isPayplusEnabled($storeId)) {
            return; // Exit if Payplus is not enabled
        }

        // Only override email settings if the payment method is Payplus
        $payment = $order->getPayment();
        $paymentMethod = $payment->getMethod();

        if ($paymentMethod !== 'payplus_gateway') {
            return;
        }

        $order->setCanSendNewEmailFlag(false);
        $order->setEmailSent(false);
        $order->setSendEmail(false);

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

    /**
     * Check if Payplus is enabled for the given store ID.
     */
    private function isPayplusEnabled($storeId)
    {
        return $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/active',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
