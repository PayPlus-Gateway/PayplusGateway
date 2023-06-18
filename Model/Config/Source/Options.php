<?php

namespace Payplus\PayplusGateway\Model\Config\Source;

class Options extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    /**
     * Get all options
     *
     * @return array
     */
    public function getAllOptions()
    {
        $this->_options = [
            ['label' => __('Daily'), 'value'=>'0'],
            ['label' => __('Weekly'), 'value'=>'1'],
            ['label' => __('Monthly'), 'value'=>'2']
        ];
        return $this->_options;
    }
}
