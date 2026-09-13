<?php

namespace Payplus\PayplusGateway\Model\Adminhtml\Source;

class CloseButtonPosition implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'auto',
                'label' => __('Auto (detect RTL/LTR)')
            ],
            [
                'value' => 'left',
                'label' => __('Left')
            ],
            [
                'value' => 'right',
                'label' => __('Right')
            ]
        ];
    }
}
