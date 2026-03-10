<?php


namespace Payplus\PayplusGateway\Controller\Ws;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use \Magento\Framework\App\CsrfAwareActionInterface;

abstract class ApiController implements CsrfAwareActionInterface, \Magento\Framework\App\ActionInterface
{
    protected $_helper;
    public $request;
    public $config;
    public $apiConnector;
    public function __construct(
        \Magento\Framework\Webapi\Rest\Request $request,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Payplus\PayplusGateway\Model\Custom\APIConnector $apiConnector
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->apiConnector = $apiConnector;
    }
    /** * @inheritDoc */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
    /** * @inheritDoc */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
