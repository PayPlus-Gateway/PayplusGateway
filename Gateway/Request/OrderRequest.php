<?php

namespace Payplus\PayplusGateway\Gateway\Request;

class OrderRequest extends BaseOrderRequest
{
    public function build(array $buildSubject)
    {
        $result = $this->collectCartData($buildSubject);
        $payment = $buildSubject['payment'];
        $result['meta']['create_token'] = false;
        $nPayment = $payment->getPayment();
        if ($nPayment
            && $this->customerSession->getCustomer()->getId()
            && isset($result['orderDetails']['customer']['customer_uid'])
            && $result['orderDetails']['customer']['customer_uid']
            ) {
            $paymentAdditionalInformation = $nPayment->getAdditionalInformation();
            if (isset($paymentAdditionalInformation['is_active_payment_token_enabler'])
                && $paymentAdditionalInformation['is_active_payment_token_enabler'] === true
            ) {
                $result['meta']['create_token'] = true;
            }
        }
        return $result;
    }
}
