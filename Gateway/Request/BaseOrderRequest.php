<?php

namespace Payplus\PayplusGateway\Gateway\Request;

use Magento\Framework\Event\Observer;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Framework\App\ResourceConnection;

define('ROUNDING_DECIMALS', 2);

abstract class BaseOrderRequest implements BuilderInterface
{
    public $session;
    public $customerSession;
    public $_logger;
    public $paymenttokenmanagement;
    public $resourceConnection;

    public function __construct(
        \Magento\Checkout\Model\Session $session,
        \Magento\Customer\Model\Session $customerSession,
        \Payplus\PayplusGateway\Logger\Logger $logger,
        \Magento\Integration\Model\Oauth\TokenFactory $tokenModelFactory,
        \Magento\Vault\Api\PaymentTokenManagementInterface $paymenttokenmanagement,
        ResourceConnection $resourceConnection
    ) {
        $this->session = $session;
        $this->customerSession = $customerSession;
        $this->_logger = $logger;
        $this->paymenttokenmanagement = $paymenttokenmanagement;
        $this->resourceConnection = $resourceConnection;
    }

    protected function collectCartData(array $buildSubject)
    {
        $totalItems = 0;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $config = $objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        if (
            !isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }
        $payment = $buildSubject['payment'];

        $order = $payment->getOrder();

        $customer = $this->customerSession->getCustomer();
        $address = $customer->getDefaultBillingAddress();

        $quote = $this->session->getQuote();
        $paymentMethod = $quote->getPayment()->getMethodInstance()->getCode();
        $public_hash = $quote->getPayment()->getAdditionalInformation('public_hash');
        $customer_id = $quote->getPayment()->getAdditionalInformation('customer_id');

        $orderDetails = [
            'charge_default' => $this->customerSession->getPayplusMethodReq(),
            'currency_code' => $order->getCurrencyCode(),
            'more_info' => $order->getOrderIncrementId()
        ];
        if ($config->getValue('payment/payplus_gateway/orders_config/payment_action', $scp) > 0) {
            $orderDetails['charge_method'] = $config->getValue(
                'payment/payplus_gateway/orders_config/payment_action',
                $scp
            );
        }

        if (intval($config->getValue(
            'payment/payplus_gateway_' . $this->customerSession->getPayplusMethodReq() . '/payment_page/hide_other_paymnet',
            $scp
        ))) {
            $orderDetails['hide_other_charge_methods'] = true;
        }
        $customer = [];
        if ($quote && $address) {
            if (method_exists($address, 'getFirstName')) {;
                $customer['email'] = $quote->getCustomerEmail();
                $customer_name = $address->getFirstName() . ' ' . $address->getLastName();
                if (!empty($address->getCompany())) {
                    $customer_name .= " (" . $address->getCompany() . " ) ";
                }
                $customer['customer_name'] =  $customer_name;
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
        if (!empty($public_hash) && !empty($customer_id)) {
            $connection = $this->resourceConnection->getConnection();
            $table = $connection->getTableName('vault_payment_token');
            $query = "Select details FROM  " . $table . " WHERE public_hash='" . $public_hash . "' AND customer_id=" . $customer_id;
            $result = $connection->fetchAll($query);
            if (count($result)) {
                $result = $result[0]['details'];
                $jsondata = str_replace('\\"', '"', $result);
                $jsondata = preg_replace('/\\\"/', "\"", $jsondata);
                $jsondata = preg_replace('/\\\'/', "\'", $jsondata);
                $result = json_decode($jsondata, true);
                $customer['customer_uid'] = $result['customer_uid'];
            }
        }
        if (!empty($customer)) {

            $orderDetails['customer'] = $customer;
        }


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
                $taxCalculation = $taxRateManager->load($taxRateID, 'tax_calculation_rate_id');
                if ($taxCalculation) {
                    $taxRate = (float)$taxCalculation->getRate();
                    if ($taxRate) {
                        $taxRate = ($taxRate + 100) / 100;
                    }
                }
            }
        }
        foreach ($order->getItems() as $item) {

            $productOptions = $item->getProductOptions();
            $productType = $item->getProductType();
            $chackSimpleNoOptionBundle = (!empty($productOptions['bundle_selection_attributes'])) ? false : true;
            // if ($productType == "bundle" || $productType=="configurable"
            //     ||( $chackSimpleNoOptionBundle && $productType == "simple") ):
            $name = $item->getName();
            if ($productType == "bundle") {

                if (!empty($productOptions['bundle_options'])) {
                    $name .= " " . $this->getOptionProduct($productOptions['bundle_options']);
                }
            }
            $itemAmount = $item->getPriceInclTax() * 100; // product price

            if ($currencyCodeTo != $currencyCodeFrom) {
                $itemAmount = $itemAmount * $rate;
            }

            $price = $itemAmount / 100;
            $totalItems += ($price * $item->getQtyOrdered());

            // Tax
            if ($item->getTaxAmount()) {
                $vat_type = 0;
            } else {
                $vat_type = 2;
            }
            $orderDetails['items'][] = [
                'name' => $name,
                'price' =>  round($price, ROUNDING_DECIMALS),
                'quantity' => $item->getQtyOrdered(),
                'barcode' => $item->getSku(),
                'vat_type' => $vat_type  // Tax
            ];
            // endif;
        }


        $shippingAmount  = $payment->getPayment()->getBaseShippingAmount();


        $itemAmount = $quote->getShippingAddress()->getShippingInclTax();
        if ($currencyCodeTo !=  $currencyCodeFrom) {
            $itemAmount =  $itemAmount * $rate;
        }
        $price =    round($itemAmount, ROUNDING_DECIMALS);
        $totalItems += $price;
        $title = ($shippingAmount) ? __('Shipping') : __('Free Shipping');
        $orderDetails['items'][] = [
            'name'         => $title,
            'price'         => $price,
            'shipping'   => true,
        ];

        $discount = $payment->getPayment()->getOrder()->getBaseDiscountAmount();

        if ($discount) {
            if ($taxRate) {
                $discount *= $taxRate;
            }
            if ($currencyCodeTo !=  $currencyCodeFrom) {
                $discount =  $discount * $rate;
            }
            $discount = round($discount, ROUNDING_DECIMALS);
            $totalItems += $discount;
            $orderDetails['items'][] = [
                'name'         => __('Discount'),
                'price'         => $discount,
                'quantity'   => 1,
            ];
        }

        $totalItems = round($totalItems, ROUNDING_DECIMALS);
        $orderDetails['amount'] = round($order->getGrandTotalAmount(), ROUNDING_DECIMALS);

        /* if ($orderDetails['amount']!== $totalItems) {

            $orderDetails['items'][] = [
                'name'         => __('Currency conversion rounding'),
                'price'         => $orderDetails['amount'] - $totalItems,
                'quantity'   => 1,
            ];
        }*/
        $orderDetails['paying_vat'] = true;

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
    public  function getOptionProduct($options)
    {
        $name = "";

        foreach ($options as $key => $option) {
            $temp = $option['label'] . " : ";
            foreach ($option['value'] as $key1 => $value) {
                $temp .= $value['title'] . " ";
            }
            $name .= (empty($name)) ? $temp : "," . $temp;
        }
        return " ( " . $name . " ) ";
    }
}
