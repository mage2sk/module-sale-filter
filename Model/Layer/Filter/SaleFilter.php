<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Layer\Filter;

use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
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
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductVisibility $productVisibility,
        private readonly StockHelper $stockHelper,
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
        $raw = $request->getParam($this->_requestVar);
        if ($raw === null || $raw === '') {
            return $this;
        }

        $value = (int) $raw;
        if ($value !== Config::VALUE_ON_SALE && $value !== Config::VALUE_NOT_ON_SALE) {
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

            $items = [];

            $onSaleCount = $this->countForScope(true);
            if ($onSaleCount > 0) {
                $items[] = [
                    'label' => $this->config->getOnSaleOptionLabel(),
                    'value' => Config::VALUE_ON_SALE,
                    'count' => $onSaleCount,
                ];
            }

            if ($this->config->isShowNotOnSaleOption()) {
                $notOnSaleCount = $this->countForScope(false);
                if ($notOnSaleCount > 0) {
                    $items[] = [
                        'label' => $this->config->getNotOnSaleOptionLabel(),
                        'value' => Config::VALUE_NOT_ON_SALE,
                        'count' => $notOnSaleCount,
                    ];
                }
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

    private function countForScope(bool $onSale): int
    {
        try {
            $collection = $this->buildCountCollection();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: unable to build count collection (%s)', $e->getMessage()),
                ['exception' => $e]
            );
            return 0;
        }

        $onSaleIds = $this->fetchOnSaleIds() ?: [0];
        $collection->addFieldToFilter(
            'entity_id',
            [$onSale ? 'in' : 'nin' => $onSaleIds]
        );

        return (int) $collection->getSize();
    }

    private function buildCountCollection(): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->setVisibility($this->productVisibility->getVisibleInCatalogIds());

        $category = $this->getLayer()->getCurrentCategory();
        if ($category && (int) $category->getId() > 0 && (int) $category->getLevel() >= 2) {
            $collection->addCategoryFilter($category);
        }

        $this->stockHelper->addIsInStockFilterToCollection($collection);

        $this->mirrorActiveLayerFilters($collection);

        return $collection;
    }

    private function mirrorActiveLayerFilters(ProductCollection $collection): void
    {
        $state = $this->getLayer()->getState();
        if ($state === null) {
            return;
        }

        foreach ($state->getFilters() as $item) {
            $filter = $item->getFilter();

            if ($filter === $this || $filter === null) {
                continue;
            }

            try {
                if ($filter instanceof \Magento\Catalog\Model\Layer\Filter\Price) {
                    $this->applyPriceFilter($collection, $item->getValue());
                } elseif ($filter instanceof \Magento\Catalog\Model\Layer\Filter\Decimal) {
                    $this->applyPriceFilter($collection, $item->getValue());
                } elseif ($filter instanceof \Magento\CatalogSearch\Model\Layer\Filter\Attribute
                    || $filter instanceof \Magento\Catalog\Model\Layer\Filter\Attribute
                ) {
                    $attribute = $filter->getAttributeModel();
                    if ($attribute && $attribute->getId()) {
                        $this->applyAttributeFilterViaEavIndex(
                            $collection,
                            (int) $attribute->getId(),
                            $item->getValue()
                        );
                    }
                } elseif ($filter instanceof \Magento\Catalog\Model\Layer\Filter\Category) {
                    $catId = (int) $item->getValue();
                    if ($catId > 0) {
                        $collection->addCategoriesFilter(['in' => [$catId]]);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Panth SaleFilter: could not mirror %s filter (%s)',
                        get_class($filter),
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function applyAttributeFilterViaEavIndex(
        ProductCollection $collection,
        int $attributeId,
        mixed $value
    ): void {
        $values = is_array($value) ? $value : [$value];
        $values = array_values(array_filter(array_map(
            static fn ($v) => is_scalar($v) ? (int) $v : 0,
            $values
        ), static fn (int $v): bool => $v > 0));

        if ($values === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $eavTable = $this->resourceConnection->getTableName('catalog_product_index_eav');

        $select = $connection->select()
            ->from(['idx' => $eavTable], ['entity_id'])
            ->where('idx.attribute_id = ?', $attributeId)
            ->where('idx.value IN (?)', $values)
            ->distinct(true);

        $matchingIds = array_map('intval', $connection->fetchCol($select));
        if ($matchingIds !== []) {
            $matchingIds = array_unique(array_merge(
                $matchingIds,
                $this->expandChildrenToParents($matchingIds)
            ));
        }

        if ($matchingIds === [] || !$this->intersectionHasRows($collection, $matchingIds)) {
            return;
        }

        $collection->addFieldToFilter('entity_id', ['in' => $matchingIds]);
    }

    private function intersectionHasRows(ProductCollection $collection, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        try {
            $probe = clone $collection;
            $probe->addFieldToFilter('entity_id', ['in' => $ids]);
            return ((int) $probe->getSize()) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function expandChildrenToParents(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_super_link');
        $select = $connection->select()
            ->from(['sl' => $table], ['parent_id'])
            ->where('sl.product_id IN (?)', $childIds)
            ->distinct(true);
        return array_map('intval', $connection->fetchCol($select));
    }

    private function applyPriceFilter(ProductCollection $collection, string $value): void
    {
        $parts = explode('-', $value);
        if (count($parts) !== 2) {
            return;
        }
        [$min, $max] = $parts;
        if ($min !== '' && is_numeric($min)) {
            $collection->addFieldToFilter('price', ['gteq' => (float) $min]);
        }
        if ($max !== '' && is_numeric($max)) {
            $collection->addFieldToFilter('price', ['lt' => (float) $max]);
        }
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
