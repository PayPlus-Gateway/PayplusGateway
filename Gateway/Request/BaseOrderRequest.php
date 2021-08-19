<?php

namespace Payplus\PayplusGateway\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

abstract class BaseOrderRequest implements BuilderInterface
{
    protected $session;
    public function __construct(
        \Magento\Checkout\Model\Session $session,
        \Magento\Customer\Model\Session $customerSession
    ) {
        $this->session= $session;
        $this->customerSession= $customerSession;
    }

    protected function collectCartData(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }
        $payment = $buildSubject['payment'];
        
        $order = $payment->getOrder();
        $address = $order->getShippingAddress();
        $quote = $this->session->getQuote();
        
        $orderDetails = [
            'currency_code'=>$order->getCurrencyCode(),
            'amount'=>$order->getGrandTotalAmount(),
            'more_info'=>$order->getOrderIncrementId()
        ];
        
        if ($quote) {
            if ($order->getCustomerId()) {
                $orderDetails['customer']['customer_uid'] = $order->getCustomerId();
            }
            $orderDetails['customer']['email'] = $quote->getCustomerEmail();
            if ($address && method_exists($address,'getName')) {
                $orderDetails['customer']['full_name'] = $address->getName();
            }
        }
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $priceCurrencyFactory = $objectManager->get('Magento\Directory\Model\CurrencyFactory');
        $storeManager = $objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $currencyCodeTo = $storeManager->getStore()->getCurrentCurrency()->getCode();
        $currencyCodeFrom = $storeManager->getStore()->getBaseCurrency()->getCode();
        $rate = $priceCurrencyFactory->create()->load($currencyCodeTo)->getAnyRate($currencyCodeFrom);
        $totalItems = 0;
        foreach ($order->getItems() as $item) { 
            $itemAmount = $item->getPriceInclTax(); // product price
            if ($currencyCodeTo !=  $currencyCodeFrom) {
                $itemAmount = round($itemAmount * $rate);
            }
            
            $orderDetails['items'][] = [
                'name'          => $item->getName(),
                'price'         => $itemAmount,
                'quantity'   => $item->getQtyOrdered(),
            ];
        }

        $shippingAmount  = $payment->getPayment()->getBaseShippingAmount();
        if ($shippingAmount) {
            $orderDetails['items'][] = [
                'name'         => 'Shipping',
                'price'         => $shippingAmount,
                'shipping'   => true,
            ];
        }
        return [
            'orderDetails' =>$orderDetails,
            'meta'=>[]
        ];
    }
}
