<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Layer\Filter;

use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\LayerProductIds;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SaleFilter extends AbstractFilter
{
    public function __construct(
        ItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        DataBuilder $itemDataBuilder,
        private readonly HttpContext $httpContext,
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly LayerProductIds $layerProductIds,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $data
        );
        $this->_requestVar = Config::FILTER_REQUEST_VAR;
    }

    public function apply(RequestInterface $request)
    {
        $value = $this->config->normalizeFilterValue($request->getParam($this->_requestVar));
        if ($value === null) {
            return $this;
        }

        if ($value === Config::VALUE_NOT_ON_SALE && !$this->config->isShowNotOnSaleOption()) {
            return $this;
        }

        try {
            $label = $value === Config::VALUE_ON_SALE
                ? $this->config->getOnSaleOptionLabel()
                : $this->config->getNotOnSaleOptionLabel();

            $filterItem = $this->_filterItemFactory->create()
                ->setFilter($this)
                ->setLabel($label)
                ->setValue($value)
                ->setCount(0);

            $this->getLayer()->getState()->addFilter($filterItem);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: unable to apply filter (%s)', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $this;
    }

    public function getName()
    {
        return $this->config->getFilterLabel();
    }

    protected function _getItemsData()
    {
        try {
            foreach ($this->getLayer()->getState()->getFilters() as $filter) {
                if ($filter->getFilter() === $this) {
                    return [];
                }
            }

            $collection = $this->getLayer()->getProductCollection();
            if ($collection->getFlag(ApplySaleFilterPlugin::COUNT_FLAG) !== null) {
                return [];
            }

            [$onSaleCount, $notOnSaleCount] = $this->countOptions();

            $items = [];
            if ($onSaleCount > 0) {
                $items[] = [
                    'label' => $this->config->getOnSaleOptionLabel(),
                    'value' => Config::VALUE_ON_SALE,
                    'count' => $onSaleCount,
                ];
            }

            if ($this->config->isShowNotOnSaleOption() && $notOnSaleCount > 0) {
                $items[] = [
                    'label' => $this->config->getNotOnSaleOptionLabel(),
                    'value' => Config::VALUE_NOT_ON_SALE,
                    'count' => $notOnSaleCount,
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: unable to build items data (%s)', $e->getMessage()),
                ['exception' => $e]
            );

            return [];
        }
    }

    private function countOptions(): array
    {
        $layerIds = $this->layerProductIds->getIds($this->getLayer());
        if ($layerIds === []) {
            return [0, 0];
        }

        $onSale = array_flip($this->fetchOnSaleIds());
        $onSaleCount = 0;
        foreach ($layerIds as $id) {
            if (isset($onSale[$id])) {
                $onSaleCount++;
            }
        }

        return [$onSaleCount, count($layerIds) - $onSaleCount];
    }

    private function fetchOnSaleIds(): array
    {
        $connection      = $this->resourceConnection->getConnection();
        $table           = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $customerGroupId = (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
        $websiteId       = (int) $this->_storeManager->getStore()->getWebsiteId();

        $select = $connection->select()
            ->from(['idx' => $table], ['entity_id'])
            ->where('idx.customer_group_id = ?', $customerGroupId)
            ->where('idx.website_id = ?', $websiteId)
            ->where('idx.is_on_sale = ?', 1);

        return array_map('intval', $connection->fetchCol($select));
    }
}
