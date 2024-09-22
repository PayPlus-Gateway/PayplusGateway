<?php

namespace Payplus\PayplusGateway\Model\Custom;

class APIConnector
{
    private $curl;
    private $addr;
    private $body;
    private $headers;
    private $logger;
    public $config;
    
    const SCPSTORES = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
    const SCPWEBSITES = \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES;
    const SCPSTORE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
    const API_CNF = 'payment/payplus_gateway/api_configuration/';
    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Payplus\PayplusGateway\Logger\Logger $logger
    ) {
        $this->curl = $curl;
        $this->config = $config;
        $this->logger =$logger;
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
        $flag=false;
        if(!empty($this->getApDavMode(self::SCPSTORES))){
            $flag=true;
        }elseif(!empty($this->getApDavMode(self::SCPWEBSITES))){
            $flag=true;
        }
        elseif(!empty($this->getApDavMode(self::SCPSTORE))){
            $flag=true;
        }
        return ($flag) ?
            'https://restapidev.payplus.co.il' : 'https://restapi.payplus.co.il';
    }

    public function getPaymentAddress()
    {
        $flag=false;
        if(!empty($this->getApDavMode(self::SCPSTORES))){
            $flag=true;
        }elseif(!empty($this->getApDavMode(self::SCPWEBSITES))){
            $flag=true;
        }
        elseif(!empty($this->getApDavMode(self::SCPSTORE))){
            $flag=true;
        }
        return ($flag) ?
            'https://paymentsdev.payplus.co.il' : 'https://payments.payplus.co.il';
    }
    public  function getApiKey($scope){
        return $this->config->getValue(self::API_CNF.'api_key', $scope);
    }
    public  function getApDavMode($scope){
        return $this->config->getValue(self::API_CNF.'dev_mode', $scope);
    }
    public  function getApiSecret($scope){
        return $this->config->getValue(self::API_CNF.'secret_key', $scope);
    }
    public  function textApi($url){
        $api_key = $this->getApiKey( self::SCPSTORES);
        $secret_key = $this->getApiSecret( self::SCPSTORES);
        $this->logger->debugOrder($url.'-Api Key And  Api Secret Key ('.self::SCPSTORES.')',array("api_key"=>$api_key,
                "api_secret"=>$secret_key));
        $api_key = $this->getApiKey( self::SCPWEBSITES);
        $secret_key = $this->getApiSecret( self::SCPWEBSITES);
        $this->logger->debugOrder($url.'-Api Key And  Api Secret Key ('.self::SCPWEBSITES.')',array("api_key"=>$api_key,
            "api_secret"=>$secret_key));

        $api_key = $this->getApiKey( self::SCPSTORE);
        $secret_key = $this->getApiSecret( self::SCPSTORE);
        $this->logger->debugOrder($url.'-Api Key And  Api Secret Key ('.self::SCPSTORE.')',array("api_key"=>$api_key,
            "api_secret"=>$secret_key));

    }
    private function getHeaders($url=null)
    {
        $this->textApi($url);
        if(!empty( $this->getApiKey( self::SCPSTORES))){
            $api_key = $this->getApiKey( self::SCPSTORES);
            $secret_key = $this->getApiSecret( self::SCPSTORES);
            $this->logger->debugOrder("current scope:",array("scope"=>self::SCPSTORES));
        }else if(!empty( $this->config->getValue(self::API_CNF.'api_key',self::SCPWEBSITES))){
            $api_key = $this->getApiKey( self::SCPWEBSITES);
            $secret_key = $this->getApiSecret( self::SCPWEBSITES);
            $this->logger->debugOrder("current scope:",array("scope"=>self::SCPWEBSITES));
        }else{
            $api_key = $this->getApiKey( self::SCPSTORE);
            $secret_key = $this->getApiSecret( self::SCPSTORE);
            $this->logger->debugOrder("current scope:",array("scope"=>self::SCPSTORE));
        }

        if ($this->headers) {
            return $this->headers;
        }
        return [
            'User-Agent'=>'Magento2',
            'Content-Type' => 'application/json',
            'Authorization' =>
            json_encode([
                'api_key' => $api_key,
                'secret_key' => $secret_key,
            ])
        ];
    }

    private function makeConnection()
    {

        $body = (is_string($this->body) ? $this->body : json_encode($this->body));
        $this->curl->setHeaders($this->getHeaders( trim($this->addr, '/')));
        $this->curl->post($this->getBaseAddress() . '/' . trim($this->addr, '/'), $body);
        $apiResponse = json_decode($this->curl->getBody(), 1);
        return $apiResponse;
    }
}
