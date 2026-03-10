<?php

namespace Payplus\PayplusGateway\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;

class VaultOrderHandler implements HandlerInterface
{
    public function handle(array $handlingSubject, array $response)
    {
        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();
        $payment->setAdditionalInformation([
            'awaiting_payment'=>true,
            'transaction_uid'=>$response['data']['transaction_uid']
        ]);

        $payment->setStatus('pre_payment')->update();
        $payment->setIsTransactionClosed(false);
    }
}
