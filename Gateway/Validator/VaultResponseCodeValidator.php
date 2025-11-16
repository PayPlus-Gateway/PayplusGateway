<?php

namespace Payplus\PayplusGateway\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use stdClass;

class VaultResponseCodeValidator extends AbstractValidator
{
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

        if ($this->isSuccessfulTransaction($response)) {
            return $this->createResult(
                true,
                []
            );
        } else {
            $error = $this->extractErrorMessage($response);
            return $this->createResult(
                false,
                [__($error->message)],
                [__($error->code)]
            );
        }
    }

    /**
     * @param array $response
     * @return bool
     */
    private function isSuccessfulTransaction(array $response)
    {
        return (isset($response['status']) && $response['status'] == 'success');
    }

    private function extractErrorMessage($response): stdClass
    {
        $result = new \stdClass;
        $result->message = 'General error';
        $result->code = 'general-error';
        if (!isset($response['status']) || $response['status'] != 'success') {
            if (isset($response['results'])) {
                $result->message = $response['results'];
            }
        } elseif (isset($response['status'])
            && $response['status'] != 'success'
            && isset($response['data']['status_code'])
            && $response['data']['status_code'] != '000'
        ) {
            $result->message = $response['data']['status_description'];
            $result->code = $response['data']['status_code'] ;
        }
        return $result;
    }
}
