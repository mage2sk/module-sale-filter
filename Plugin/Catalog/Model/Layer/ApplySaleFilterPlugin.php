<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\Config;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ApplySaleFilterPlugin
{
    public const APPLIED_FLAG = 'panth_salefilter_applied';
    public const ITEMS_FLAG   = 'panth_salefilter_ids';
    public const COUNT_FLAG   = 'panth_salefilter_size';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpContext $httpContext,
        private readonly ProductVisibility $productVisibility,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger,
        private readonly StockHelper $stockHelper,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function afterGetProductCollection(Layer $subject, $collection)
    {
        if (!$collection instanceof ProductCollection) {
            return $collection;
        }
        if ($collection->getFlag(self::APPLIED_FLAG)) {
            return $collection;
        }
        $collection->setFlag(self::APPLIED_FLAG, true);

        if (!$this->config->isEnabled()) {
            return $collection;
        }

        $raw = $this->request->getParam(Config::FILTER_REQUEST_VAR);
        if ($raw === null || $raw === '') {
            return $collection;
        }

        $value = (int) $raw;
        if ($value !== Config::VALUE_ON_SALE && $value !== Config::VALUE_NOT_ON_SALE) {
            return $collection;
        }
        if ($value === Config::VALUE_NOT_ON_SALE && !$this->config->isShowNotOnSaleOption()) {
            return $collection;
        }

        try {
            $allowedIds = $this->resolveFilteredIds($subject, $value);

            $collection->setFlag(self::ITEMS_FLAG, $allowedIds);
            $collection->setFlag(self::COUNT_FLAG, count($allowedIds));

            $collection->getSelect()->where('e.entity_id IN (?)', $allowedIds ?: [0]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: plugin failed to apply filter (%s)', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $collection;
    }

    private function resolveFilteredIds(Layer $subject, int $value): array
    {
        $category = $subject->getCurrentCategory();
        $hasCategoryScope = $category
            && (int) $category->getId() > 0
            && (int) $category->getLevel() >= 2;

        if (!$hasCategoryScope) {
            return $value === Config::VALUE_ON_SALE ? $this->fetchOnSaleIds() : [];
        }

        $onSaleIds = $this->fetchOnSaleIds();

        $membership = $onSaleIds ?: [0];

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->setVisibility($this->productVisibility->getVisibleInCatalogIds());
        $collection->addCategoryFilter($category);

        $this->stockHelper->addIsInStockFilterToCollection($collection);

        $this->mirrorActiveLayerFilters($subject, $collection);

        $collection->addFieldToFilter(
            'entity_id',
            [$value === Config::VALUE_ON_SALE ? 'in' : 'nin' => $membership]
        );

        $this->applySort($collection);

        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }
        return $ids;
    }

    private function mirrorActiveLayerFilters(Layer $subject, ProductCollection $collection): void
    {
        $reserved = [
            Config::FILTER_REQUEST_VAR,
            'p', 'page', 'limit', 'product_list_limit',
            'mode', 'product_list_mode',
            'order', 'product_list_order',
            'dir', 'product_list_dir',
            'q', 'id',
        ];

        $params = [];
        try {
            $params = (array) $this->request->getParams();
        } catch (\Throwable) {
            return;
        }

        foreach ($params as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $reserved, true)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }

            try {
                if ($key === 'price') {
                    $parts = explode('-', (string) $value);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    [$min, $max] = $parts;
                    if ($min !== '' && is_numeric($min)) {
                        $collection->addFieldToFilter('price', ['gteq' => (float) $min]);
                    }
                    if ($max !== '' && is_numeric($max)) {
                        $collection->addFieldToFilter('price', ['lt' => (float) $max]);
                    }
                    continue;
                }

                if ($key === 'cat') {
                    $catId = (int) $value;
                    if ($catId > 0) {
                        $collection->addCategoriesFilter(['in' => [$catId]]);
                    }
                    continue;
                }

                $attribute = $this->resolveFilterableAttribute($key);
                if ($attribute === null) {
                    continue;
                }
                $this->applyAttributeFilterViaEavIndex(
                    $collection,
                    (int) $attribute->getId(),
                    $value
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Panth SaleFilter: could not mirror request param "%s" (%s)',
                        $key,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function resolveFilterableAttribute(string $code): ?\Magento\Catalog\Model\ResourceModel\Eav\Attribute
    {
        try {
            $attribute = $this->eavConfig->getAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $code
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$attribute || !$attribute->getId()) {
            return null;
        }

        if ((int) $attribute->getIsFilterable() === 0
            && (int) $attribute->getIsFilterableInSearch() === 0
        ) {
            return null;
        }

        return $attribute;
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
        $superLinkTable = $this->resourceConnection->getTableName('catalog_product_super_link');

        $select = $connection->select()
            ->from(['idx' => $eavTable], ['entity_id'])
            ->where('idx.attribute_id = ?', $attributeId)
            ->where('idx.value IN (?)', $values)
            ->distinct(true);
        $matchingIds = array_map('intval', $connection->fetchCol($select));

        if ($matchingIds !== []) {
            $parentSelect = $connection->select()
                ->from(['sl' => $superLinkTable], ['parent_id'])
                ->where('sl.product_id IN (?)', $matchingIds)
                ->distinct(true);
            $parents = array_map('intval', $connection->fetchCol($parentSelect));
            if ($parents !== []) {
                $matchingIds = array_unique(array_merge($matchingIds, $parents));
            }
        }

        if ($matchingIds === []
            || !$this->intersectionHasRows($collection, $matchingIds)
        ) {
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

    private function applySort(ProductCollection $collection): void
    {
        $sortAttr = (string) $this->request->getParam('product_list_order', '');
        $sortDir  = strtoupper((string) $this->request->getParam('product_list_dir', 'asc'));
        if ($sortDir !== 'ASC' && $sortDir !== 'DESC') {
            $sortDir = 'ASC';
        }

        if ($sortAttr === '' || $sortAttr === 'position') {
            $collection->addAttributeToSort('position', $sortDir);
            return;
        }

        if (in_array($sortAttr, ['price', 'name'], true)) {
            $collection->addAttributeToSort($sortAttr, $sortDir);
            return;
        }
    }

    private function fetchOnSaleIds(): array
    {
        $connection      = $this->resourceConnection->getConnection();
        $table           = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $customerGroupId = (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
        $websiteId       = (int) $this->storeManager->getStore()->getWebsiteId();

        $select = $connection->select()
            ->from(['idx' => $table], ['entity_id'])
            ->where('idx.customer_group_id = ?', $customerGroupId)
            ->where('idx.website_id = ?', $websiteId)
            ->where('idx.is_on_sale = ?', 1);

        return array_map('intval', $connection->fetchCol($select));
    }
}
