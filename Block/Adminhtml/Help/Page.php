<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Block\Adminhtml\Help;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Url as UrlGenerator;

class Page extends Template
{
    public function __construct(
        Context $context,
        private readonly ModuleListInterface $moduleList,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getModuleVersion(): string
    {
        $mod = $this->moduleList->getOne('Panth_SaleFilter');
        return (string) ($mod['setup_version'] ?? '1.0.x');
    }

    public function getHyvaModuleVersion(): string
    {
        $mod = $this->moduleList->getOne('Panth_SaleFilterHyva');
        return $mod ? (string) ($mod['setup_version'] ?? '1.0.x') : 'not installed';
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_salefilter']);
    }

    public function getGridUrl(): string
    {
        return $this->getUrl('panth_salefilter/index/index');
    }

    public function getReindexUrl(): string
    {
        return $this->getUrl('panth_salefilter/index/reindex');
    }

    public function getCatalogRulesUrl(): string
    {
        return $this->getUrl('catalog_rule/promo_catalog/index');
    }

    public function getIndexerUrl(): string
    {
        return $this->getUrl('indexer/indexer/list');
    }
}
