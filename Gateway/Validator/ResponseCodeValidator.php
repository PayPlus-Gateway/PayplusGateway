<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;

class ResponseCodeValidator extends AbstractValidator
{
    protected $_logger;
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        \Payplus\PayplusGateway\Logger\Logger $logger,
        \Magento\Framework\App\Config\ScopeConfigInterface $config
    ) {
        parent::__construct($resultFactory);
        $this->_logger = $logger;
        $this->config = $config;
    }
    const RESULT_CODE = 'RESULT_CODE';

    /**
     * Performs validation of result code
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        if (!isset($validationSubject['response']) || !is_array($validationSubject['response'])) {
            throw new \InvalidArgumentException('Response does not exist');
        }

        $response = $validationSubject['response'];
        $this->_logger->debugOrder("Order response", $response);
        if ($this->isSuccessfulTransaction($response)) {
            return $this->createResult(
                true,
                []
            );
        } else {
            return $this->createResult(
                false,
                [__('Gateway rejected the transaction.')],
                [
                    'gatewayresponse'=>json_encode($response),
                    'paymentData'=> json_encode($validationSubject['payment']->getPayment()->toArray())
                ]
            );
        }
    }

    /**
     * @param array $response
     * @return bool
     */
    private function isSuccessfulTransaction(array $response)
    {
        return (isset($response['results']) && $response['results']['status'] == 'success');
    }
}
