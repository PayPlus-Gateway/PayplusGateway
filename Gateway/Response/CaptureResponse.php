<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;

class CaptureResponse implements HandlerInterface
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
        
        $existingAdditionalInfo = $payment->getAdditionalInformation();
        $existingAdditionalInfo['chargeOrderResponse'] = $response;
        $payment->setAdditionalInformation($existingAdditionalInfo);
        $payment->setTransactionId($response['data']['transaction']['uid']);
        $payment->getOrder()->save();
    }
}
