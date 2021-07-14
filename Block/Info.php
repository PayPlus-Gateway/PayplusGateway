<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Block;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;

class Info extends ConfigurableInfo
{
    /**
     * Returns label
     *
     * @param string $field
     * @return Phrase
     */
    protected function getLabel($field): Phrase
    {
        return __($field);
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $priceHelper = $objectManager->create(\Magento\Framework\Pricing\Helper\Data::class);
        $transport = parent::_prepareSpecificInformation($transport);
        /**
         * @var \Magento\Sales\Model\Order\Payment\Interceptor
         */
        $info = $this->getInfo();
        $displayData = [];
        $additionalInformation = $info->getAdditionalInformation();
        if (isset($additionalInformation['paymentPageResponse'])) {
            $adPage = [];
            $pageData = $additionalInformation['paymentPageResponse'];

            $textCapturedReturn =  ($pageData['type'] == 'Approval') ? 'authorized':'charged';
            $adPage['Status'] = $pageData['status'].' ('. $pageData['status_code'].')';
            $adPage['Status description'] = $pageData['status_description'];
            $adPage['Amount '.$textCapturedReturn] = $priceHelper->currency($pageData['amount'], true, false, 'USD');
            if (isset($additionalInformation['paymentPageResponse']['number_of_payments'])
                && $additionalInformation['paymentPageResponse']['number_of_payments'] > 1) {
                $adPage['Number of payments'] = $pageData['number_of_payments'];
                $adPage['First payment'] = $pageData['first_payment_amount'];
                $adPage['Subsequent payments'] = $pageData['rest_payments_amount'];
            }
            if (isset($additionalInformation['paymentPageResponse']['identification_number'])) {
                $adPage['Identification card number'] = $pageData['identification_number'];
            }
            $displayData['Checkout page response'] = $adPage;
        }
        if (isset($additionalInformation['chargeOrderResponse'])) {
            $adCharge = [];
            $chargeInfo = $additionalInformation['chargeOrderResponse'];
            $statusCodeShrt = $chargeInfo['data']['transaction']['status_code'];
            $adCharge['Status'] = $chargeInfo['results']['status'].' ('. $statusCodeShrt .')';
            $adCharge['Status description'] =  $chargeInfo['results']['description'];
            $amountShrt = $chargeInfo['data']['transaction']['amount'];
            $adCharge['Amount charged'] = $priceHelper->currency($amountShrt, true, false, 'USD');
            $adCharge['Approval number'] =  $chargeInfo['data']['transaction']['approval_number'];
            $displayData['Capture response'] = $adCharge;
        }
        if (isset($additionalInformation['refundResponse'])) {
            $additionalRefund = $additionalInformation['refundResponse'];
            $refundResponse = [];
            $statusCodeShrt = $additionalRefund['data']['transaction']['status_code'];
            $refundResponse['Status'] = $additionalRefund['results']['status'].' ('. $statusCodeShrt.')';
            $refundResponse['Status description'] =  $additionalRefund['results']['description'];
            $amountShrt = $additionalRefund['data']['transaction']['amount'];
            $refundResponse['Amount refunded'] = $priceHelper->currency($amountShrt, true, false, 'USD');
            
            $displayData['Refund Response'] = $refundResponse;
        }

        return $transport->setData($displayData);
    }

    public function beforeToHtml(\Magento\Payment\Block\Info $subject)
    {
        if ($subject->getMethod()->getCode() == \Payplus\PayplusGateway\Model\Ui\ConfigProvider::CODE) {
            $subject->setTemplate('Payplus_PayplusGateway::info/default.phtml');
        } else {
            parent::_beforeToHtml($subject);
        }
    }
}
