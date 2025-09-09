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

            // Check if this has related transactions (ANY payment method combination)
            $hasRelatedTransactions = isset($pageData['related_transactions']) && is_array($pageData['related_transactions']) && count($pageData['related_transactions']) > 0;
            $isMultipleTransaction = isset($pageData['is_multiple_transaction']) && $pageData['is_multiple_transaction'] === true;

            $textCapturedReturn =  ($pageData['type'] == 'Approval') ? 'authorized' : 'charged';
            $adPage['Status'] = $pageData['status'] . ' (' . $pageData['status_code'] . ')';

            if (isset($pageData['status_description'])) {
                $frontDisplayData[__('Status description')->render()] = $adPage[__('Status description')->render()] = $pageData['status_description'];
            }

            $adPage[__('Amount %1', $textCapturedReturn)->render()] = $priceHelper->currency($pageData['amount'], true, false, 'USD');

            // Handle ANY transaction with related_transactions (multipass, bit+credit, etc.)
            if ($hasRelatedTransactions) {
                if ($isMultipleTransaction) {
                    $adPage[__('Payment Method')->render()] = __('Multiple Payment Methods')->render();
                    $frontDisplayData[__('Payment Method')->render()] = __('Multiple Payment Methods')->render();
                } else {
                    $adPage[__('Payment Method')->render()] = __('Combined Payment Transaction')->render();
                    $frontDisplayData[__('Payment Method')->render()] = __('Combined Payment Transaction')->render();
                }

                // Process each related transaction
                foreach ($pageData['related_transactions'] as $index => $txn) {
                    $txnPrefix = __('Payment %1', ($index + 1))->render();

                    // Determine payment method type
                    $methodType = $txn['method'] ?? 'unknown';
                    $isAlternativeMethod = isset($txn['alternative_method']) && $txn['alternative_method'] === true;

                    if ($methodType === 'credit-card') {
                        $adPage[$txnPrefix . ' ' . __('Type')->render()] = __('Credit Card')->render();
                        $frontDisplayData[$txnPrefix . ' ' . __('Type')->render()] = __('Credit Card')->render();

                        if (isset($txn['four_digits'])) {
                            $adPage[$txnPrefix . ' ' . __('Last four digits')->render()] = $txn['four_digits'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Last four digits')->render()] = $txn['four_digits'];
                        }
                        if (isset($txn['brand_name'])) {
                            $adPage[$txnPrefix . ' ' . __('Card')->render()] = $txn['brand_name'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Card')->render()] = $txn['brand_name'];
                        }
                        if (isset($txn['clearing_name'])) {
                            $adPage[$txnPrefix . ' ' . __('Clearing')->render()] = $txn['clearing_name'];
                        }
                        if (isset($txn['brand_code'])) {
                            $adPage[$txnPrefix . ' ' . __('Brand code')->render()] = $txn['brand_code'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Brand code')->render()] = $txn['brand_code'];
                        }
                        if (isset($txn['expiry_month']) && isset($txn['expiry_year'])) {
                            $adPage[$txnPrefix . ' ' . __('Expiry')->render()] = $txn['expiry_month'] . '/' . $txn['expiry_year'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Expiry')->render()] = $txn['expiry_month'] . '/' . $txn['expiry_year'];
                        }
                        if (isset($txn['card_holder_name'])) {
                            $adPage[$txnPrefix . ' ' . __('Cardholder')->render()] = $txn['card_holder_name'];
                        }
                    } else {
                        // Any alternative payment method (multipass, bit, etc.)
                        $methodName = __('Unknown Method')->render();

                        if (isset($txn['alternative_method_name'])) {
                            $methodName = $txn['alternative_method_name'];
                        } elseif (isset($txn['clearing_name'])) {
                            $methodName = $txn['clearing_name'];
                        } elseif (isset($txn['brand_name'])) {
                            $methodName = $txn['brand_name'];
                        } else {
                            $methodName = ucfirst($methodType);
                        }

                        $adPage[$txnPrefix . ' ' . __('Type')->render()] = $methodName;
                        $frontDisplayData[$txnPrefix . ' ' . __('Type')->render()] = $methodName;

                        if (isset($txn['alternative_method_name'])) {
                            $adPage[$txnPrefix . ' ' . __('Method')->render()] = $txn['alternative_method_name'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Method')->render()] = $txn['alternative_method_name'];
                        }
                        if (isset($txn['four_digits'])) {
                            $adPage[$txnPrefix . ' ' . __('ID')->render()] = $txn['four_digits'];
                            $frontDisplayData[$txnPrefix . ' ' . __('ID')->render()] = $txn['four_digits'];
                        }
                        if (isset($txn['brand_name'])) {
                            $adPage[$txnPrefix . ' ' . __('Brand')->render()] = $txn['brand_name'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Brand')->render()] = $txn['brand_name'];
                        }
                        if (isset($txn['clearing_name'])) {
                            $adPage[$txnPrefix . ' ' . __('Clearing')->render()] = $txn['clearing_name'];
                        }
                        if (isset($txn['brand_code'])) {
                            $adPage[$txnPrefix . ' ' . __('Brand code')->render()] = $txn['brand_code'];
                            $frontDisplayData[$txnPrefix . ' ' . __('Brand code')->render()] = $txn['brand_code'];
                        }
                    }

                    // Common fields for ALL payment types
                    $adPage[$txnPrefix . ' ' . __('Amount')->render()] = $priceHelper->currency($txn['amount'], true, false, 'USD');
                    $frontDisplayData[$txnPrefix . ' ' . __('Amount')->render()] = $priceHelper->currency($txn['amount'], true, false, 'USD');

                    if (isset($txn['approval_num'])) {
                        $adPage[$txnPrefix . ' ' . __('Approval number')->render()] = $txn['approval_num'];
                        $frontDisplayData[$txnPrefix . ' ' . __('Approval number')->render()] = $txn['approval_num'];
                    }
                    if (isset($txn['voucher_num'])) {
                        $adPage[$txnPrefix . ' ' . __('Voucher number')->render()] = $txn['voucher_num'];
                        $frontDisplayData[$txnPrefix . ' ' . __('Voucher number')->render()] = $txn['voucher_num'];
                    }
                    if (isset($txn['status']) && isset($txn['status_code'])) {
                        $adPage[$txnPrefix . ' ' . __('Status')->render()] = $txn['status'] . ' (' . $txn['status_code'] . ')';
                    }
                }
            } else {
                // Regular single payment processing
                if (
                    isset($additionalInformation['paymentPageResponse']['number_of_payments'])
                    && $additionalInformation['paymentPageResponse']['number_of_payments'] > 1
                ) {
                    $adPage[__('Number of payments')->render()] = $pageData['number_of_payments'];
                    $adPage[__('First payment')->render()] = $pageData['first_payment_amount'];
                    $adPage[__('Subsequent payments')->render()] = $pageData['rest_payments_amount'];
                }
                if (isset($additionalInformation['paymentPageResponse']['token_uid'])) {
                    $adPage[__('Token')->render()] = $pageData['token_uid'];
                }

                if (isset($additionalInformation['paymentPageResponse']['voucher_num'])) {
                    $adPage[__('Voucher number')->render()] = $pageData['voucher_num'];
                }

                if (isset($additionalInformation['paymentPageResponse']['c'])) {
                    $adPage[__('More info')->render()] = $pageData['more_info'];
                }

                if (isset($additionalInformation['paymentPageResponse']['alternative_name'])) {
                    $adPage[__('Alternative name')->render()] = $pageData['alternative_name'];
                }

                if (isset($additionalInformation['paymentPageResponse']['identification_number'])) {
                    $adPage[__('Identification card number')->render()] = $pageData['identification_number'];
                }
                if (isset($additionalInformation['paymentPageResponse']['approval_num'])) {
                    $frontDisplayData[__('Approval number')->render()] = $adPage[__('Approval number')->render()] = $pageData['approval_num'];
                }
                if (isset($additionalInformation['paymentPageResponse']['clearing_name'])) {
                    $frontDisplayData[__('Card')->render()] = $adPage[__('Card')->render()] = $pageData['clearing_name'];
                }
                if (isset($additionalInformation['paymentPageResponse']['four_digits'])) {
                    $frontDisplayData[__('Last four digits')->render()] = $adPage[__('Last four digits')->render()] = $pageData['four_digits'];
                }
                if (isset($additionalInformation['paymentPageResponse']['brand_code'])) {
                    $frontDisplayData[__('Brand code')->render()] = $adPage[__('Brand code')->render()] = $pageData['brand_code'];
                }
                if (isset($additionalInformation['paymentPageResponse']['brand_name'])) {
                    $frontDisplayData[__('Brand name')->render()] = $adPage[__('Brand name')->render()] = $pageData['brand_name'];
                }

                if (
                    isset($additionalInformation['paymentPageResponse']['expiry_month'])
                    && isset($additionalInformation['paymentPageResponse']['expiry_year'])
                ) {
                    $frontDisplayData[__('Expiry')->render()] = $adPage[__('Expiry')->render()] = $additionalInformation['paymentPageResponse']['expiry_month']
                        . '/' . $additionalInformation['paymentPageResponse']['expiry_year'];
                }
                if (isset($additionalInformation['paymentPageResponse']['invoice_original_url'])) {
                    $adPage[__('Url Invoice')->render()] = $pageData['invoice_original_url'];
                }
            }

            $displayData[__('Checkout page response')->render()] = $adPage;
        }
        if (isset($additionalInformation['chargeOrderResponse'])) {


            $adCharge = [];
            $chargeInfo = $additionalInformation['chargeOrderResponse'];
            $statusCodeShrt = $chargeInfo['data']['transaction']['status_code'];
            $adCharge[__('Status')->render()] = $chargeInfo['results']['status'] . ' (' . $statusCodeShrt . ')';
            $adCharge[__('Status description')->render()] =  $chargeInfo['results']['description'];
            $amountShrt = $chargeInfo['data']['transaction']['amount'];
            $adCharge[__('Amount charged')->render()] = $priceHelper->currency($amountShrt, true, false, 'USD');
            $adCharge[__('Approval number')->render()] =  $chargeInfo['data']['transaction']['approval_number'];
            $displayData[__('Capture response')->render()] = $adCharge;
        }
        if (isset($additionalInformation['refundResponse'])) {
            $additionalRefund = $additionalInformation['refundResponse'];
            $refundResponse = [];
            $statusCodeShrt = $additionalRefund['data']['transaction']['status_code'];
            $refundResponse[__('Status')->render()] = $additionalRefund['results']['status'] . ' (' . $statusCodeShrt . ')';
            $refundResponse[__('Status description')->render()] =  $additionalRefund['results']['description'];
            $amountShrt = $additionalRefund['data']['transaction']['amount'];
            $refundResponse[__('Amount refunded')->render()] = $priceHelper->currency($amountShrt, true, false, 'USD');

            $displayData[__('Refund Response')->render()] = $refundResponse;
        }

        if ($this->getArea() != 'adminhtml') {

            return $transport->setData([__('Checkout page response')->render() . ':' => $frontDisplayData]);
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
