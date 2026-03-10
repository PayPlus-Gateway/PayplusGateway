<?php

namespace Payplus\PayplusGateway\Gateway\Request;

class VaultOrderRequest extends BaseOrderRequest
{
    public function build(array $buildSubject)
    {
        $result = $this->collectCartData($buildSubject);
        $payment = $buildSubject['payment'];
        $nPayment = $payment->getPayment();
        $customerID = $this->customerSession->getCustomer()->getId();
        if ($nPayment
            && $customerID !== null
            && $customerID
            && isset($result['orderDetails']['customer']['customer_uid'])
            && $result['orderDetails']['customer']['customer_uid']
            ) {
            $ExtensionAttributes = $nPayment->getExtensionAttributes()->getVaultPaymentToken();
            if ($ExtensionAttributes) {
                $token = $ExtensionAttributes->getGatewayToken();
                if ($token && $customerID == $ExtensionAttributes->getCustomerId()) {
                    $tokenDetails = json_decode($ExtensionAttributes->getTokenDetails());
                    if ($tokenDetails && isset($tokenDetails->customer_uid)) {
                        $result['meta']['token'] = $token;
                        $result['meta']['customer_uid'] = $tokenDetails->customer_uid;
                    }
                }
            }
        } else {
            throw new \InvalidArgumentException(__('Credit card token unavailable'));
        }
        return $result;
    }
}
