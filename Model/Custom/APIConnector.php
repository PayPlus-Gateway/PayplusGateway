<?php

namespace Payplus\PayplusGateway\Model\Custom;

class APIConnector
{
    private $curl;
    private $addr;
    private $body;
    private $headers;
    const SCP = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
    const API_CNF = 'payment/payplus_gateway/api_configuration/';
    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\Config\ScopeConfigInterface $config
    ) {
        $this->curl = $curl;
        $this->config = $config;
    }

    public function checkTransactionAgainstIPN($body)
    {
        $this->addr = '/api/v1.0/PaymentPages/ipn';
        $this->body = json_encode($body);
        return $this->makeConnection();
    }

    public function prepareRequest($addr, $body)
    {
        $this->addr = $addr;
        $this->body = $body;
        return $this->makeConnection();
    }

    public function getBaseAddress()
    {
        return ($this->config->getValue(self::API_CNF.'dev_mode', self::SCP)) ?
            'https://restapidev.payplus.co.il' : 'https://restapi.payplus.co.il';
    }

    public function getPaymentAddress()
    {
        return ($this->config->getValue(self::API_CNF.'dev_mode', self::SCP)) ?
            'https://paymentsdev.payplus.co.il' : 'https://payments.payplus.co.il';
    }

    private function getHeaders()
    {
        if ($this->headers) {
            return $this->headers;
        }
        return [
            'User-Agent'=>'Magento2',
            'Content-Type' => 'application/json',
            'Authorization' =>
            json_encode([
                'api_key' => $this->config->getValue(self::API_CNF.'api_key', self::SCP),
                'secret_key' => $this->config->getValue(self::API_CNF.'secret_key', self::SCP),
            ])
        ];
    }

    private function makeConnection()
    {
        $body = (is_string($this->body) ? $this->body : json_encode($this->body));
        $this->curl->setHeaders($this->getHeaders());
        $this->curl->post($this->getBaseAddress() . '/' . trim($this->addr, '/'), $body);
        $apiResponse = json_decode($this->curl->getBody(), 1);
        return $apiResponse;
    }
}
