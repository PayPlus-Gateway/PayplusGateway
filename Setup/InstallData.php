<?php

use Magento\Catalog\Model\Product;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;


class InstallData implements InstallDataInterface
{
    private $eavSetupFactory;
    private $categorySetupFactory;
    private $config;
    private $logger;
    private  $productCollectionFactory;
    public function __construct(EavSetupFactory $eavSetupFactory, CategorySetupFactory $categorySetupFactory,
                                \Magento\Framework\App\Config\ScopeConfigInterface $config,
                                \Payplus\PayplusGateway\Logger\Logger $logger)
    {
        $this->eavSetupFactory = $eavSetupFactory;
        $this->categorySetupFactory = $categorySetupFactory;
        $this->config =$config;
        $this->logger =$logger;
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {

       $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
       $subsciptionEnabled =$this->config->getValue('payment/payplus_gateway/payment_page/subsciption_enabled', $scp) ;

        $this->logger->debugOrder('subsciptionEnabled',array($subsciptionEnabled));
       // if($subsciptionEnabled==1) {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
            $categorySetup = $this->categorySetupFactory->create(['setup' => $setup]);

            $eavSetup->addAttribute(
                Product::ENTITY,
                'subsciption_enabled',
                [
                    'type' => 'int',
                    'label' => 'Subsciption Enabled',
                    'input' => 'boolean',
                    'source' => 'Magento\Eav\Model\Entity\Attribute\Source\Boolean',
                    'required' => false,
                    'visible' => true,
                    'user_defined' => true,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'visible_on_front' => true,
                    'used_in_product_listing' => true,
                    'unique' => false,
                    'apply_to' => '',
                    'system' => 1,
                    'group' => 'General',
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'sort_order' => 999,
                    'position' => 999
                ]
            );
            // Add your attribute to the attribute set(s)
            $attributeId = $eavSetup->getAttributeId(Product::ENTITY, 'subsciption_enabled');
            $attributeSetId = $categorySetup->getDefaultAttributeSetId(Product::ENTITY);
            $categorySetup->addAttributeToSet(Product::ENTITY, $attributeSetId, 'General', $attributeId);

            $eavSetup->addAttribute(
                Product::ENTITY,
                'recurring_type',
                [
                    'type' => 'int',
                    'label' => 'Freqyency',
                    'input' => 'select',
                    'source' => \Payplus\PayplusGateway\Model\Config\Source\Options::class,
                    'required' => false,
                    'visible' => true,
                    'user_defined' => true,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'visible_on_front' => true,
                    'used_in_product_listing' => true,
                    'unique' => false,
                    'apply_to' => '',
                    'system' => 1,
                    'group' => 'General',
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'sort_order' => 1000,
                    'position' => 1000,
                    'option' => [
                        ['label' => __('Daily'), 'value' => '0'],
                        ['label' => __('Weekly'), 'value' => '1'],
                        ['label' => __('Monthly'), 'value' => '2'],
                    ]]);
            $attributeId = $eavSetup->getAttributeId(Product::ENTITY, 'recurring_type');
            $attributeSetId = $categorySetup->getDefaultAttributeSetId(Product::ENTITY);
            $categorySetup->addAttributeToSet(Product::ENTITY, $attributeSetId, 'General', $attributeId);

            $eavSetup->addAttribute(
                Product::ENTITY,
                'number_of_charges',
                [
                    'type' => 'varchar',
                    'label' => 'Repeat Every',
                    'input' => 'text',
                    'source' => '',
                    'required' => false,
                    'visible' => true,
                    'user_defined' => true,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'visible_on_front' => true,
                    'used_in_product_listing' => true,
                    'unique' => false,
                    'apply_to' => '',
                    'system' => 1,
                    'group' => 'General',
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'sort_order' => 1001,
                    'position' => 1001,
                    'default' => 0
                ]
            );
            // Add your attribute to the attribute set(s)
            $attributeId = $eavSetup->getAttributeId(Product::ENTITY, 'number_of_charges');
            $attributeSetId = $categorySetup->getDefaultAttributeSetId(Product::ENTITY);
            $categorySetup->addAttributeToSet(Product::ENTITY, $attributeSetId, 'General', $attributeId);

            $eavSetup->addAttribute(
                Product::ENTITY,
                'jump_payments',
                [
                    'type' => 'varchar',
                    'label' => 'Trial Days',
                    'input' => 'text',
                    'source' => '',
                    'required' => false,
                    'visible' => true,
                    'user_defined' => true,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'visible_on_front' => true,
                    'used_in_product_listing' => true,
                    'unique' => false,
                    'apply_to' => '',
                    'system' => 1,
                    'group' => 'General',
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'sort_order' => 1002,
                    'position' => 1002,
                    'default' => 0
                ]
            );
            // Add your attribute to the attribute set(s)
            $attributeId = $eavSetup->getAttributeId(Product::ENTITY, 'jump_payments');
            $attributeSetId = $categorySetup->getDefaultAttributeSetId(Product::ENTITY);
            $categorySetup->addAttributeToSet(Product::ENTITY, $attributeSetId, 'General', $attributeId);

        }

  //  }
}
