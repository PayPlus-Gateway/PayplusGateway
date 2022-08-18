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
        $this->session = $session;
        $this->customerSession = $customerSession;
        $this->_logger = $logger;
    }

    protected function collectCartData(array $buildSubject)
    {
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
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
            'currency_code' => $order->getCurrencyCode(),
            'more_info' => $order->getOrderIncrementId()
        ];
        $customer = [];
        if ($quote && $address) {
            if (method_exists($address, 'getFirstName')) {
                $customer['email'] = $quote->getCustomerEmail();
                $customer['customer_name'] = $address->getFirstName() . ' ' . $address->getLastName();
                $customer['city'] = $address->getCity();
                $customer['country_iso'] = $address->getCountryId();
                if (method_exists($address, 'getStreet')) {
                    $addressLines = $address->getStreet();
                    if ($addressLines && is_array($addressLines)) {
                        $customer['address'] = implode(' ', $addressLines);
                    }
                } elseif (method_exists($address, 'getStreetLine1')) {
                    $customer['address'] = $address->GetStreetLine1() . ' ' . $address->GetStreetLine2();
                }
            }
        }

        if (!empty($customer)) {

            $orderDetails['customer'] = $customer;
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $config = $objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $priceCurrencyFactory = $objectManager->get(\Magento\Directory\Model\CurrencyFactory::class);
        $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
        $currencyCodeTo = $storeManager->getStore()->getCurrentCurrency()->getCode();
        $currencyCodeFrom = $storeManager->getStore()->getBaseCurrency()->getCode();
        $rate = $priceCurrencyFactory->create()->load($currencyCodeTo)->getAnyRate($currencyCodeFrom);
        $taxRateID = $quote->getCustomerTaxClassId();
        $taxRate = null;
        if ($taxRateID) {
            $taxRateManager = $objectManager->get(\Magento\Tax\Model\Calculation\Rate::class);
            if ($taxRateManager) {
                $taxCalculation = $taxRateManager->load($taxRateID , 'tax_calculation_rate_id' );
                if ($taxCalculation) {
                    $taxRate = (float)$taxCalculation->getRate();
                    if ($taxRate) {
                        $taxRate = ($taxRate + 100) / 100;
                    }
                }
            }
        }

        foreach ($order->getItems() as $item) {
            $itemAmount = $item->getPriceInclTax() * 100; // product price
            if ($currencyCodeTo !=  $currencyCodeFrom) {
                $itemAmount = $itemAmount * $rate;
            }
            $orderDetails['items'][] = [
                'name'          => $item->getName(),
                'price'         => floor($itemAmount) / 100,
                'quantity'   => $item->getQtyOrdered(),
                'barcode'   => $item->getSku(),
            ];
        }

        $shippingAmount  = $payment->getPayment()->getBaseShippingAmount();
        if ($shippingAmount) {
            $itemAmount = $quote->getShippingAddress()->getShippingInclTax();
            if ($currencyCodeTo !=  $currencyCodeFrom) {
                $itemAmount =  $itemAmount * $rate;
            }
            $orderDetails['items'][] = [
                'name'         => __('Shipping'),
                'price'         => $itemAmount,
                'shipping'   => true,
            ];
        }


        $discount = $order->getBaseDiscountAmount();
        if ($discount) {
            if ($taxRate) {
                $discount *=$taxRate;
            }
            if ($currencyCodeTo !=  $currencyCodeFrom) {
                $discount =  $discount * $rate;
            }
            $orderDetails['items'][] = [
                'name'         => __('Discount'),
                'price'         => $discount,
                'quantity'   => 1,
            ];
        }

        $totalItems = 0;
        foreach ($orderDetails['items'] as $item) {
            $quantity = ($item['quantity']) ?? 1;
            $totalItems += ($item['price'] * $quantity);
        }
        $orderDetails['amount'] = $order->getGrandTotalAmount();
        if ($orderDetails['amount'] != $totalItems) {
            $orderDetails['items'][] = [
                'name'         => __('Currency conversion rounding'),
                'price'         => $orderDetails['amount'] - $totalItems,
                'quantity'   => 1,
            ];
        }
        $orderDetails['paying_vat'] = true;

        //roee 27-06-2022
        if ($config->getValue('payment/payplus_gateway/invoices_config/no_vat_if_set_to_no_vat', $scp)  == 0) {
           $appliedTaxes = $quote->getShippingAddress()->getAppliedTaxes();

            if ($appliedTaxes !== null && empty($appliedTaxes)) {
                $orderDetails['paying_vat'] = false;
            }
        }

        return [
            'orderDetails' => $orderDetails,
            'meta' => []
        ];
    }
}
