<?php

namespace Payplus\PayplusGateway\Controller\Adminhtml\SyncOrders;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Payplus\PayplusGateway\Model\Service\OrderSyncService;
use Magento\Framework\Controller\Result\JsonFactory;
use Payplus\PayplusGateway\Logger\Logger;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator;

class Index extends Action implements CsrfAwareActionInterface
{
    protected $orderSyncService;
    protected $resultJsonFactory;
    protected $logger;
    protected $formKeyValidator;

    public function __construct(
        Context $context,
        OrderSyncService $orderSyncService,
        JsonFactory $resultJsonFactory,
        Logger $logger,
        Validator $formKeyValidator
    ) {
        parent::__construct($context);
        $this->orderSyncService = $orderSyncService;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->formKeyValidator = $formKeyValidator;
    }

    /**
     * Check admin permissions
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::sales_order');
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Validate form key using Magento's validator
        return $this->formKeyValidator->validate($request);
    }

    /**
     * Execute sync action
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        // Log request details for debugging
        try {
            $this->logger->debugOrder('OrderSync Manual: Request received', [
                'form_key' => $this->getRequest()->getParam('form_key') ? 'present' : 'missing',
                'method' => $this->getRequest()->getMethod(),
                'is_post' => $this->getRequest()->isPost()
            ]);
        } catch (\Exception $logException) {
            // Log error if logging fails
            error_log('OrderSync: Logger error: ' . $logException->getMessage());
        }

        try {
            $this->logger->debugOrder('OrderSync Manual: Starting sync process', []);
            
            // Check if service is available
            if (!$this->orderSyncService) {
                throw new \Exception('OrderSyncService is not available');
            }
            
            try {
                $report = $this->orderSyncService->syncTodayOrders();
            } catch (\Exception $serviceException) {
                $this->logger->debugOrder('OrderSync Manual: Service error', [
                    'error' => $serviceException->getMessage(),
                    'trace' => $serviceException->getTraceAsString(),
                    'file' => $serviceException->getFile(),
                    'line' => $serviceException->getLine()
                ]);
                throw $serviceException;
            }
            
            $this->logger->debugOrder('OrderSync Manual: Completed', $report);

            $result->setHttpResponseCode(200);
            $result->setData([
                'success' => true,
                'message' => __('Order sync completed successfully'),
                'report' => $report
            ]);
        } catch (\Throwable $e) {
            // Catch both Exception and Error
            try {
                $this->logger->debugOrder('OrderSync Manual: Error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'type' => get_class($e)
                ]);
            } catch (\Exception $logError) {
                // If logging fails, at least return the error
                error_log('OrderSync: Cannot log error: ' . $logError->getMessage());
            }
            
            $result->setHttpResponseCode(500);
            $result->setData([
                'success' => false,
                'message' => __('Error: %1', $e->getMessage()),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'type' => get_class($e)
            ]);
        }

        return $result;
    }
}

