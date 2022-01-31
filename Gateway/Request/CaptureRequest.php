<?php

namespace Payplus\PayplusGateway\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;

class CaptureRequest extends BaseOrderRequest
{
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }
        $payment = $buildSubject['payment'];
        $order = $payment->getOrder();
        $orderDetails = [
            'currency_code' => $order->getCurrencyCode(),
            'more_info' => $order->getOrderIncrementId(),
            'transaction_uid'=>$payment->getPayment()->getLastTransId(),
            'amount'=>$buildSubject['amount']
        ];
        return $orderDetails;
    }
}
