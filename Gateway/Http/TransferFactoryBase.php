<?php

namespace Payplus\PayplusGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferFactoryInterface;

abstract class TransferFactoryBase implements TransferFactoryInterface
{
    protected $gatewayMethod;
    public $config;
    public function __construct(\Magento\Framework\App\Config\ScopeConfigInterface $config)
    {
        $this->config = $config;
    }
}
