<?php

/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Payplus\PayplusGateway\Block;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use Magento\Sales\Helper\AdminTest;
use Magento\Setup\Module\Di\Code\Reader\Decorator\Area;

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
        $frontDisplayData = [];

        $additionalInformation = $info->getAdditionalInformation();

        if (isset($additionalInformation['paymentPageResponse'])) {

            $adPage = [];
            $pageData = $additionalInformation['paymentPageResponse'];

            $textCapturedReturn =  ($pageData['type'] == 'Approval') ? 'authorized' : 'charged';
            $adPage['Status'] = $pageData['status'] . ' (' . $pageData['status_code'] . ')';

            $frontDisplayData['Status description'] = $adPage['Status description'] = $pageData['status_description'];
            $adPage['Amount ' . $textCapturedReturn] = $priceHelper->currency($pageData['amount'], true, false, 'USD');
            if (
                isset($additionalInformation['paymentPageResponse']['number_of_payments'])
                && $additionalInformation['paymentPageResponse']['number_of_payments'] > 1
            ) {
                $adPage['Number of payments'] = $pageData['number_of_payments'];
                $adPage['First payment'] = $pageData['first_payment_amount'];
                $adPage['Subsequent payments'] = $pageData['rest_payments_amount'];
            }
            if (isset($additionalInformation['paymentPageResponse']['token_uid'])) {
                $adPage['Token'] = $pageData['token_uid'];
            }

            if (isset($additionalInformation['paymentPageResponse']['voucher_num'])) {
                $adPage['Voucher number'] = $pageData['voucher_num'];
            }

            if (isset($additionalInformation['paymentPageResponse']['c'])) {
                $adPage['More info'] = $pageData['more_info'];
            }

            if (isset($additionalInformation['paymentPageResponse']['alternative_name'])) {
                $adPage['Alternative name'] = $pageData['alternative_name'];
            }

            if (isset($additionalInformation['paymentPageResponse']['identification_number'])) {
                $adPage['Identification card number'] = $pageData['identification_number'];
            }
            if (isset($additionalInformation['paymentPageResponse']['approval_num'])) {
                $frontDisplayData['Approval number'] = $adPage['Approval number'] = $pageData['approval_num'];
            }
            if (isset($additionalInformation['paymentPageResponse']['clearing_name'])) {
                $frontDisplayData['Card'] = $adPage['Card'] = $pageData['clearing_name'];
            }
            if (isset($additionalInformation['paymentPageResponse']['four_digits'])) {
                $frontDisplayData['Last four digits'] = $adPage['Last four digits'] = $pageData['four_digits'];
            }

            if (
                isset($additionalInformation['paymentPageResponse']['expiry_month'])
                && isset($additionalInformation['paymentPageResponse']['expiry_year'])
            ) {
                $frontDisplayData['Expiry'] = $adPage['Expiry'] = $additionalInformation['paymentPageResponse']['expiry_month']
                    . '/' . $additionalInformation['paymentPageResponse']['expiry_year'];
            }
            if (isset($additionalInformation['paymentPageResponse']['invoice_original_url'])) {
                $adPage['Url Invoice'] = $pageData['invoice_original_url'];
            }

            $displayData['Checkout page response'] = $adPage;
        }
        if (isset($additionalInformation['chargeOrderResponse'])) {


            $adCharge = [];
            $chargeInfo = $additionalInformation['chargeOrderResponse'];
            $statusCodeShrt = $chargeInfo['data']['transaction']['status_code'];
            $adCharge['Status'] = $chargeInfo['results']['status'] . ' (' . $statusCodeShrt . ')';
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
            $refundResponse['Status'] = $additionalRefund['results']['status'] . ' (' . $statusCodeShrt . ')';
            $refundResponse['Status description'] =  $additionalRefund['results']['description'];
            $amountShrt = $additionalRefund['data']['transaction']['amount'];
            $refundResponse['Amount refunded'] = $priceHelper->currency($amountShrt, true, false, 'USD');

            $displayData['Refund Response'] = $refundResponse;
        }

        if ($this->getArea() != 'adminhtml') {

            return $transport->setData(['Checkout page response:' => $frontDisplayData]);
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
