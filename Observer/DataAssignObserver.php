<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;

class DataAssignObserver extends AbstractDataAssignObserver
{
    protected  $customerSession;
    public function __construct(
        \Magento\Customer\Model\Session $customerSession
    ){
        $this->customerSession = $customerSession;
    }
    /**
     * @param Observer $observer
     * @return void
     */


    public function execute(Observer $observer)
    {

        $method = $this->readMethodArgument($observer);
        $data = $this->readDataArgument($observer);
        $payplusmethodreq =$data->getDataByKey('additional_data');


        $paymentInfo = $method->getInfoInstance();


        if(!empty($payplusmethodreq['payplusmethodreq'])){
            $payplusmethodreq =str_replace('_','-',$payplusmethodreq['payplusmethodreq']);

            $this->customerSession->setPayplusMethodReq($payplusmethodreq);
        }

        if ($data->getDataByKey('transaction_result') !== null) {
            $paymentInfo->setAdditionalInformation(
                'transaction_result',
                $data->getDataByKey('transaction_result')
            );
        }
        if ($data->getDataByKey('is_active_payment_token_enabler') !== null) {
            $paymentInfo->setAdditionalInformation(
                'is_active_payment_token_enabler',
                $data->getDataByKey('is_active_payment_token_enabler')
            );
        }
    }
}
