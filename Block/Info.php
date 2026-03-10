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
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Hebrew labels mapping
     * @var array
     */
    protected $hebrewLabels = [
        'Payment Method' => 'אמצעי תשלום',
        'Multiple Payment Methods' => 'מספר אמצעי תשלום',
        'Combined Payment Transaction' => 'עסקת תשלום משולבת',
        'Payment %d' => 'תשלום %d',
        'Type' => 'סוג',
        'Credit Card' => 'כרטיס אשראי',
        'Last four digits' => 'ארבע ספרות אחרונות',
        'Card' => 'כרטיס',
        'Clearing' => 'קלירינג',
        'Brand code' => 'קוד מותג',
        'Expiry' => 'תוקף',
        'Cardholder' => 'בעל הכרטיס',
        'Unknown Method' => 'אמצעי לא מוכר',
        'Method' => 'אמצעי',
        'ID' => 'מזהה',
        'Brand' => 'מותג',
        'Amount' => 'סכום',
        'Approval number' => 'מספר אישור',
        'Voucher number' => 'מספר שובר',
        'Status' => 'סטטוס',
        'Number of payments' => 'מספר תשלומים',
        'First payment' => 'תשלום ראשון',
        'Subsequent payments' => 'תשלומים נוספים',
        'Token' => 'טוקן',
        'More info' => 'מידע נוסף',
        'Alternative name' => 'שם חלופי',
        'Identification card number' => 'מספר תעודת זהות',
        'Brand name' => 'שם מותג',
        'Url Invoice' => 'קישור חשבונית',
        'Checkout page response' => 'מענה מעמוד התשלום',
        'Status description' => 'תיאור הסטטוס',
        'Amount charged' => 'סכום שחויב',
        'Capture response' => 'מענה לכידה',
        'Amount refunded' => 'סכום שהוחזר',
        'Refund Response' => 'מענה החזר',
        'authorized' => 'מורשה',
        'charged' => 'חויב'
    ];

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

    /**
     * Get label based on Hebrew configuration
     *
     * @param string $label
     * @param mixed ...$params
     * @return string
     */
    protected function getDisplayLabel($label, ...$params)
    {
        if ($this->isHebrewDisplayEnabled()) {
            if (isset($this->hebrewLabels[$label])) {
                $hebrewLabel = $this->hebrewLabels[$label];
                if (!empty($params)) {
                    return sprintf($hebrewLabel, ...$params);
                }
                return $hebrewLabel;
            }
        }

        if (!empty($params)) {
            return sprintf($label, ...$params);
        }
        return $label;
    }

    /**
     * Check if Hebrew display is enabled
     *
     * @return bool
     */
    protected function isHebrewDisplayEnabled()
    {
        if (!$this->scopeConfig) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $this->scopeConfig = $objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        }

        return $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/display_settings/display_payment_info_hebrew',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
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

            // Check if this has related transactions (ANY payment method combination)
            $hasRelatedTransactions = isset($pageData['related_transactions']) && is_array($pageData['related_transactions']) && count($pageData['related_transactions']) > 0;
            $isMultipleTransaction = isset($pageData['is_multiple_transaction']) && $pageData['is_multiple_transaction'] === true;

            $textCapturedReturn =  ($pageData['type'] == 'Approval') ? 'authorized' : 'charged';
            $adPage['Status'] = $pageData['status'] . ' (' . $pageData['status_code'] . ')';

            if (isset($pageData['status_description'])) {
                $frontDisplayData[$this->getDisplayLabel('Status description')] = $adPage[$this->getDisplayLabel('Status description')] = $pageData['status_description'];
            }

            $textCapturedReturn = ($pageData['type'] == 'Approval') ? $this->getDisplayLabel('authorized') : $this->getDisplayLabel('charged');
            $adPage[$this->getDisplayLabel('Amount') . ' ' . $textCapturedReturn] = $priceHelper->currency($pageData['amount'], true, false, 'USD');

            // Handle ANY transaction with related_transactions (multipass, bit+credit, etc.)
            if ($hasRelatedTransactions) {
                if ($isMultipleTransaction) {
                    $adPage[$this->getDisplayLabel('Payment Method')] = $this->getDisplayLabel('Multiple Payment Methods');
                    $frontDisplayData[$this->getDisplayLabel('Payment Method')] = $this->getDisplayLabel('Multiple Payment Methods');
                } else {
                    $adPage[$this->getDisplayLabel('Payment Method')] = $this->getDisplayLabel('Combined Payment Transaction');
                    $frontDisplayData[$this->getDisplayLabel('Payment Method')] = $this->getDisplayLabel('Combined Payment Transaction');
                }

                // Process each related transaction
                foreach ($pageData['related_transactions'] as $index => $txn) {
                    $txnPrefix = $this->getDisplayLabel('Payment %d', ($index + 1));

                    // Determine payment method type
                    $methodType = $txn['method'] ?? 'unknown';
                    $isAlternativeMethod = isset($txn['alternative_method']) && $txn['alternative_method'] === true;

                    if ($methodType === 'credit-card') {
                        $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Type')] = $this->getDisplayLabel('Credit Card');
                        $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Type')] = $this->getDisplayLabel('Credit Card');

                        if (isset($txn['four_digits'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Last four digits')] = $txn['four_digits'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Last four digits')] = $txn['four_digits'];
                        }
                        if (isset($txn['brand_name'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Card')] = $txn['brand_name'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Card')] = $txn['brand_name'];
                        }
                        if (isset($txn['clearing_name'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Clearing')] = $txn['clearing_name'];
                        }
                        if (isset($txn['brand_code'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Brand code')] = $txn['brand_code'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Brand code')] = $txn['brand_code'];
                        }
                        if (isset($txn['expiry_month']) && isset($txn['expiry_year'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Expiry')] = $txn['expiry_month'] . '/' . $txn['expiry_year'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Expiry')] = $txn['expiry_month'] . '/' . $txn['expiry_year'];
                        }
                        if (isset($txn['card_holder_name'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Cardholder')] = $txn['card_holder_name'];
                        }
                    } else {
                        // Any alternative payment method (multipass, bit, etc.)
                        $methodName = $this->getDisplayLabel('Unknown Method');

                        if (isset($txn['alternative_method_name'])) {
                            $methodName = $txn['alternative_method_name'];
                        } elseif (isset($txn['clearing_name'])) {
                            $methodName = $txn['clearing_name'];
                        } elseif (isset($txn['brand_name'])) {
                            $methodName = $txn['brand_name'];
                        } else {
                            $methodName = ucfirst($methodType);
                        }

                        $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Type')] = $methodName;
                        $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Type')] = $methodName;

                        if (isset($txn['alternative_method_name'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Method')] = $txn['alternative_method_name'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Method')] = $txn['alternative_method_name'];
                        }
                        if (isset($txn['four_digits'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('ID')] = $txn['four_digits'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('ID')] = $txn['four_digits'];
                        }
                        if (isset($txn['brand_name'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Brand')] = $txn['brand_name'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Brand')] = $txn['brand_name'];
                        }
                        if (isset($txn['clearing_name'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Clearing')] = $txn['clearing_name'];
                        }
                        if (isset($txn['brand_code'])) {
                            $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Brand code')] = $txn['brand_code'];
                            $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Brand code')] = $txn['brand_code'];
                        }
                    }

                    // Common fields for ALL payment types
                    $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Amount')] = $priceHelper->currency($txn['amount'], true, false, 'USD');
                    $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Amount')] = $priceHelper->currency($txn['amount'], true, false, 'USD');

                    if (isset($txn['approval_num'])) {
                        $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Approval number')] = $txn['approval_num'];
                        $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Approval number')] = $txn['approval_num'];
                    }
                    if (isset($txn['voucher_num'])) {
                        $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Voucher number')] = $txn['voucher_num'];
                        $frontDisplayData[$txnPrefix . ' ' . $this->getDisplayLabel('Voucher number')] = $txn['voucher_num'];
                    }
                    if (isset($txn['status']) && isset($txn['status_code'])) {
                        $adPage[$txnPrefix . ' ' . $this->getDisplayLabel('Status')] = $txn['status'] . ' (' . $txn['status_code'] . ')';
                    }
                }
            } else {
                // Regular single payment processing
                if (
                    isset($additionalInformation['paymentPageResponse']['number_of_payments'])
                    && $additionalInformation['paymentPageResponse']['number_of_payments'] > 1
                ) {
                    $adPage[$this->getDisplayLabel('Number of payments')] = $pageData['number_of_payments'];
                    $adPage[$this->getDisplayLabel('First payment')] = $pageData['first_payment_amount'];
                    $adPage[$this->getDisplayLabel('Subsequent payments')] = $pageData['rest_payments_amount'];
                }
                if (isset($additionalInformation['paymentPageResponse']['token_uid'])) {
                    $adPage[$this->getDisplayLabel('Token')] = $pageData['token_uid'];
                }

                if (isset($additionalInformation['paymentPageResponse']['voucher_num'])) {
                    $adPage[$this->getDisplayLabel('Voucher number')] = $pageData['voucher_num'];
                }

                if (isset($additionalInformation['paymentPageResponse']['c'])) {
                    $adPage[$this->getDisplayLabel('More info')] = $pageData['more_info'];
                }

                if (isset($additionalInformation['paymentPageResponse']['alternative_name'])) {
                    $adPage[$this->getDisplayLabel('Alternative name')] = $pageData['alternative_name'];
                }

                if (isset($additionalInformation['paymentPageResponse']['identification_number'])) {
                    $adPage[$this->getDisplayLabel('Identification card number')] = $pageData['identification_number'];
                }
                if (isset($additionalInformation['paymentPageResponse']['approval_num'])) {
                    $frontDisplayData[$this->getDisplayLabel('Approval number')] = $adPage[$this->getDisplayLabel('Approval number')] = $pageData['approval_num'];
                }
                if (isset($additionalInformation['paymentPageResponse']['clearing_name'])) {
                    $frontDisplayData[$this->getDisplayLabel('Card')] = $adPage[$this->getDisplayLabel('Card')] = $pageData['clearing_name'];
                }
                if (isset($additionalInformation['paymentPageResponse']['four_digits'])) {
                    $frontDisplayData[$this->getDisplayLabel('Last four digits')] = $adPage[$this->getDisplayLabel('Last four digits')] = $pageData['four_digits'];
                }
                if (isset($additionalInformation['paymentPageResponse']['brand_code'])) {
                    $frontDisplayData[$this->getDisplayLabel('Brand code')] = $adPage[$this->getDisplayLabel('Brand code')] = $pageData['brand_code'];
                }
                if (isset($additionalInformation['paymentPageResponse']['brand_name'])) {
                    $frontDisplayData[$this->getDisplayLabel('Brand name')] = $adPage[$this->getDisplayLabel('Brand name')] = $pageData['brand_name'];
                }

                if (
                    isset($additionalInformation['paymentPageResponse']['expiry_month'])
                    && isset($additionalInformation['paymentPageResponse']['expiry_year'])
                ) {
                    $frontDisplayData[$this->getDisplayLabel('Expiry')] = $adPage[$this->getDisplayLabel('Expiry')] = $additionalInformation['paymentPageResponse']['expiry_month']
                        . '/' . $additionalInformation['paymentPageResponse']['expiry_year'];
                }
                if (isset($additionalInformation['paymentPageResponse']['invoice_original_url'])) {
                    $adPage[$this->getDisplayLabel('Url Invoice')] = $pageData['invoice_original_url'];
                }
            }

            $displayData[$this->getDisplayLabel('Checkout page response')] = $adPage;
        }
        if (isset($additionalInformation['chargeOrderResponse'])) {


            $adCharge = [];
            $chargeInfo = $additionalInformation['chargeOrderResponse'];
            $statusCodeShrt = $chargeInfo['data']['transaction']['status_code'];
            $adCharge[$this->getDisplayLabel('Status')] = $chargeInfo['results']['status'] . ' (' . $statusCodeShrt . ')';
            $adCharge[$this->getDisplayLabel('Status description')] =  $chargeInfo['results']['description'];
            $amountShrt = $chargeInfo['data']['transaction']['amount'];
            $adCharge[$this->getDisplayLabel('Amount charged')] = $priceHelper->currency($amountShrt, true, false, 'USD');
            $adCharge[$this->getDisplayLabel('Approval number')] =  $chargeInfo['data']['transaction']['approval_number'];
            $displayData[$this->getDisplayLabel('Capture response')] = $adCharge;
        }
        if (isset($additionalInformation['refundResponse'])) {
            $additionalRefund = $additionalInformation['refundResponse'];
            $refundResponse = [];
            $statusCodeShrt = $additionalRefund['data']['transaction']['status_code'];
            $refundResponse[$this->getDisplayLabel('Status')] = $additionalRefund['results']['status'] . ' (' . $statusCodeShrt . ')';
            $refundResponse[$this->getDisplayLabel('Status description')] =  $additionalRefund['results']['description'];
            $amountShrt = $additionalRefund['data']['transaction']['amount'];
            $refundResponse[$this->getDisplayLabel('Amount refunded')] = $priceHelper->currency($amountShrt, true, false, 'USD');

            $displayData[$this->getDisplayLabel('Refund Response')] = $refundResponse;
        }

        if ($this->getArea() != 'adminhtml') {

            return $transport->setData([$this->getDisplayLabel('Checkout page response') . ':' => $frontDisplayData]);
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
