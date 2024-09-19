<?php
namespace Payplus\PayplusGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferInterface;

class CaptureTransferFactory extends TransferFactoryBase
{
    public $config;
    public $storeManager;
    protected $gatewayMethod = '/api/v1.0/Transactions/ChargeByTransactionUID';
    /**
     * @var TransferBuilder
     */
    private $transferBuilder;

    /**
     * @param TransferBuilder $transferBuilder
     */
    public function __construct(
        TransferBuilder $transferBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->transferBuilder = $transferBuilder;
        $this->config = $config;
        $this->storeManager= $storeManager;
        parent::__construct($config);
    }

    /**
     * Builds gateway transfer object
     *
     * @param array $request
     * @return TransferInterface
     */
    public function create(array $request)
    {
        return $this->transferBuilder
            ->setBody($request)
            ->setUri($this->gatewayMethod)
            ->build();
    }
}
