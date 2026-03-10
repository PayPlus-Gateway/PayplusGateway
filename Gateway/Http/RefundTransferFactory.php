<?php

namespace Payplus\PayplusGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;

class RefundTransferFactory extends TransferFactoryBase implements TransferFactoryInterface
{
    protected $gatewayMethod = '/api/v1.0/Transactions/RefundByTransactionUID';
    public $transferBuilder;
    public function __construct(
        TransferBuilder $transferBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $config
    ) {
        $this->transferBuilder = $transferBuilder;
        parent::__construct($config);
    }

    public function create(array $request)
    {
        return $this->transferBuilder
            ->setBody($request)
            ->setUri($this->gatewayMethod)
            ->build();
    }
}
