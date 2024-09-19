<?php

namespace Payplus\PayplusGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;

class VaultOrderTransferFactory extends TransferFactoryBase implements TransferFactoryInterface
{
    public $config;
    public $storeManager;
    protected $gatewayMethod = '/api/v1.0/PaymentPages/generateLink';
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
        $this->storeManager= $storeManager;
        parent::__construct($config);
    }
    
    public function create(array $data)
    {
        $request = $data['orderDetails'];
        
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $request['payment_page_uid'] = $this->config->getValue(
            'payment/payplus_gateway/api_configuration/payment_page_uid',
            $scp
        );
        if ($this->config->getValue('payment/payplus_gateway/orders_config/payment_action', $scp) > 0) {
            $request['charge_method'] = $this->config->getValue(
                'payment/payplus_gateway/orders_config/payment_action',
                $scp
            );
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/send_add_data_param', $scp) == 1) {
            $request['add_data'] = "1";
        }
        if ($this->config->getValue('payment/payplus_gateway/orders_config/email_upon_success', $scp)) {
            $request['sendEmailApproval'] = true;
        }
        if ($this->config->getValue('payment/payplus_gateway/orders_config/sendEmailFailure', $scp)) {
            $request['sendEmailFailure'] = true;
        }

        $request['token'] = $data['meta']['token'];
        $request['use_token'] = true;
        $request['credit_terms'] = 1;
        $request['create_token'] = false;
        $transfer = $this->transferBuilder
            ->setBody($request)
            ->setUri($this->gatewayMethod)
            ->build();
        return $transfer;
    }
}
