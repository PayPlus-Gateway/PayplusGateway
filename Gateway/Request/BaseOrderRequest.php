<?php

namespace Payplus\PayplusGateway\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

abstract class BaseOrderRequest implements BuilderInterface
{
    protected $session;
    public function __construct(
        \Magento\Checkout\Model\Session $session,
        \Magento\Customer\Model\Session $customerSession,
        \Payplus\PayplusGateway\Logger\Logger $logger
    ) {
        $this->session= $session;
        $this->customerSession= $customerSession;
        $this->_logger = $logger;
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
        
        if ($quote) {
            if ($order->getCustomerId()) {
                $orderDetails['customer']['customer_uid'] = $order->getCustomerId();
            }
            $orderDetails['customer']['email'] = $quote->getCustomerEmail();
            if ($address && method_exists($address, 'getName')) {
                $orderDetails['customer']['full_name'] = $address->getName();
            }
        }
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $priceCurrencyFactory = $objectManager->get(\Magento\Directory\Model\CurrencyFactory::class);
        $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
        $currencyCodeTo = $storeManager->getStore()->getCurrentCurrency()->getCode();
        $currencyCodeFrom = $storeManager->getStore()->getBaseCurrency()->getCode();
        $rate = $priceCurrencyFactory->create()->load($currencyCodeTo)->getAnyRate($currencyCodeFrom);
        
        $orderDetails = [
            'currency_code'=>$order->getCurrencyCode(),
            'more_info'=>$order->getOrderIncrementId()
        ];
        foreach ($order->getItems() as $item) {
            $itemAmount = $item->getPriceInclTax() * 100; // product price
            if ($currencyCodeTo !=  $currencyCodeFrom) {
                $itemAmount = $itemAmount * $rate;
            }
            $orderDetails['items'][] = [
                'name'          => $item->getName(),
                'price'         => floor($itemAmount) / 100,
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
        $totalItems = 0;
        foreach ($orderDetails['items'] as $item) {
            $quantity = ($item['quantity'])?:1;
            $totalItems+= ($item['price'] * $quantity);
        }
        $orderDetails['amount'] = $order->getGrandTotalAmount();
        if ($currencyCodeTo != $currencyCodeFrom && $orderDetails['amount'] != $totalItems) {
            $orderDetails['items'][] = [
                'name'         => __('Currency conversion rounding'),
                'price'         => $orderDetails['amount'] - $totalItems,
                'quantity'   => 1,
            ];
        }
        return [
            'orderDetails' =>$orderDetails,
            'meta'=>[]
        ];
    }
}
