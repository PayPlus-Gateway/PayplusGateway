<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class Client implements ClientInterface
{
    public $apiConnector;
    public function __construct(
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector
    ) {
        $this->apiConnector = $apiConnector;
    }

    /**
     * Places request to gateway. Returns result as ENV array
     *
     * @param TransferInterface $transferObject
     * @return array
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        return $this->apiConnector->prepareRequest(
            $transferObject->getUri(),
            $transferObject->getBody()
        );
    }
}
