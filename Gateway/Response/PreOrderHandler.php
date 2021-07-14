<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;

class PreOrderHandler implements HandlerInterface
{
    const TXN_ID = 'TXN_ID';

    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();
        $payment->setAdditionalData($response['data']['page_request_uid']);
        $payment->setAdditionalInformation(['awaiting_payment'=>true]);
        $payment->setStatus('pre_payment')->update();
        $payment->setIsTransactionClosed(false);
    }
}
