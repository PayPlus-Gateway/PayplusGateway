<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

class ConfigChange implements ObserverInterface
{
    public $request;
    public $configWriter;
    public $mathRandom;
    public $curl;
    public function __construct(
        RequestInterface $request,
        WriterInterface $configWriter,
        \Magento\Framework\Math\Random $mathRandom,
        \Magento\Framework\HTTP\Client\Curl $curl
    ) {
        $this->request = $request;
        $this->configWriter = $configWriter;
        $this->mathRandom = $mathRandom;
        $this->curl = $curl;
    }
    public function execute(EventObserver $observer)
    {
        return $this;
    }
}
