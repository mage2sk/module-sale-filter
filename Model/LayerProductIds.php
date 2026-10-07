<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Search as SearchLayer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\FilterBuilderFactory;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\Search\SearchInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Search\Model\QueryFactory;

class LayerProductIds
{
    public const REQUEST_CATALOG = 'catalog_view_container';
    public const REQUEST_SEARCH  = 'quick_search_container';
    public const MAX_RESULTS     = 10000;

    private const PRICE_DELTA = 0.001;

    private const RESERVED_PARAMS = [
        Config::FILTER_REQUEST_VAR,
        'p', 'page', 'limit', 'product_list_limit',
        'mode', 'product_list_mode',
        'order', 'product_list_order',
        'dir', 'product_list_dir',
        'q', 'id', 'cat', 'price',
    ];

    private array $cache = [];

    public function __construct(
        private readonly SearchInterface $search,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilderFactory $filterBuilderFactory,
        private readonly ProductVisibility $productVisibility,
        private readonly RequestInterface $request,
        private readonly EavConfig $eavConfig,
        private readonly QueryFactory $queryFactory,
        private readonly ActiveSaleFilter $activeSaleFilter
    ) {
    }

    public function getIds(Layer $layer): array
    {
        $isSearch = $layer instanceof SearchLayer;
        $queryText = '';
        if ($isSearch) {
            $query = $this->queryFactory->get();
            if ($query->isQueryTextShort()) {
                return [];
            }
            $raw = $query->getQueryText();
            $queryText = is_scalar($raw) ? trim((string) $raw) : '';
            if ($queryText === '') {
                return [];
            }
        }

        $category = $layer->getCurrentCategory();
        $category = $category instanceof Category ? $category : null;
        $filters = $this->collectFilters($category, $isSearch, $queryText);
        $orders = $this->resolveOrders($category, $isSearch);

        $key = hash('sha256', (string) json_encode([$isSearch, $filters, $orders]));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $criteriaBuilder = $this->searchCriteriaBuilderFactory->create();
        $filterBuilder = $this->filterBuilderFactory->create();
        foreach ($filters as $field => $value) {
            $criteriaBuilder->addFilter($filterBuilder->setField($field)->setValue($value)->create());
        }

        $criteria = $criteriaBuilder->create();
        $criteria->setRequestName($isSearch ? self::REQUEST_SEARCH : self::REQUEST_CATALOG);
        $criteria->setSortOrders($orders);
        $criteria->setPageSize(self::MAX_RESULTS);
        $criteria->setCurrentPage(0);

        $ids = [];
        $result = $this->activeSaleFilter->runWithoutRestriction(
            fn () => $this->search->search($criteria)
        );
        foreach ($result->getItems() as $item) {
            $id = (int) $item->getId();
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $this->cache[$key] = array_values($ids);

        return $this->cache[$key];
    }

    private function collectFilters(?Category $category, bool $isSearch, string $queryText): array
    {
        $filters = [];
        if ($isSearch) {
            $filters['search_term'] = $queryText;
        }
        $filters['visibility'] = $isSearch
            ? $this->productVisibility->getVisibleInSearchIds()
            : $this->productVisibility->getVisibleInCatalogIds();

        $categoryId = $this->toPositiveInt($this->request->getParam('cat'));
        if ($categoryId === 0 && !$isSearch && $category !== null) {
            $categoryId = (int) $category->getId();
        }
        if ($categoryId > 0) {
            $filters['category_ids'] = $categoryId;
        }

        $this->addPriceFilter($filters, $this->request->getParam('price'));

        $params = (array) $this->request->getParams();
        foreach ($params as $code => $value) {
            if (!is_string($code) || $code === '' || in_array($code, self::RESERVED_PARAMS, true)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $attribute = $this->resolveFilterableAttribute($code, $isSearch);
            if ($attribute === null) {
                continue;
            }
            if ($attribute->getBackendType() === 'decimal') {
                $this->addDecimalFilter($filters, $code, $value);
                continue;
            }
            $converted = $this->convertAttributeValue((string) $attribute->getBackendType(), $value);
            if ($converted !== null) {
                $filters[$code] = $converted;
            }
        }

        return $filters;
    }

    private function addPriceFilter(array &$filters, mixed $value): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        $parts = explode('-', explode(',', $value)[0]);
        if (count($parts) !== 2) {
            return;
        }
        foreach ($parts as $part) {
            if ($part !== '' && $part !== '0'
                && (!is_numeric($part) || (float) $part <= 0 || is_infinite((float) $part))
            ) {
                return;
            }
        }

        $from = (float) $parts[0];
        $to = (float) $parts[1];
        if ($to > 0 && $from !== $to) {
            $to -= self::PRICE_DELTA;
        }
        if ($from > 0) {
            $filters['price.from'] = $from;
        }
        if ($to > 0) {
            $filters['price.to'] = $to;
        }
    }

    private function addDecimalFilter(array &$filters, string $code, mixed $value): void
    {
        if (!is_string($value)) {
            return;
        }
        $parts = explode('-', $value);
        if (count($parts) !== 2) {
            return;
        }
        $from = (float) $parts[0];
        $to = (float) $parts[1];
        if ($from > 0) {
            $filters[$code . '.from'] = $from;
        }
        if ($to > 0) {
            $filters[$code . '.to'] = $to;
        }
    }

    private function convertAttributeValue(string $backendType, mixed $value): mixed
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $values[] = $backendType === 'int' ? (int) $item : (string) $item;
                }
            }
            return $values === [] ? null : $values;
        }
        if (!is_scalar($value)) {
            return null;
        }

        return $backendType === 'int' ? (int) $value : (string) $value;
    }

    private function resolveFilterableAttribute(string $code, bool $isSearch): ?object
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (\Throwable) {
            return null;
        }
        if (!$attribute || !$attribute->getId()) {
            return null;
        }
        $flag = $isSearch ? $attribute->getIsFilterableInSearch() : $attribute->getIsFilterable();

        return (int) $flag > 0 ? $attribute : null;
    }

    private function resolveOrders(?Category $category, bool $isSearch): array
    {
        $order = $this->request->getParam('product_list_order');
        $order = is_string($order) ? $order : '';
        if (!$this->isSortable($order, $isSearch)) {
            $order = $isSearch || $category === null ? '' : (string) $category->getDefaultSortBy();
            if (!$this->isSortable($order, $isSearch)) {
                $order = $isSearch ? 'relevance' : 'position';
            }
        }

        $dir = $this->request->getParam('product_list_dir');
        $dir = is_string($dir) ? strtoupper($dir) : '';
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = $order === 'relevance' ? 'DESC' : 'ASC';
        }

        return [$order => $dir, 'entity_id' => 'ASC'];
    }

    private function isSortable(string $order, bool $isSearch): bool
    {
        if ($order === '' || $order === 'entity_id') {
            return false;
        }
        if ($order === ($isSearch ? 'relevance' : 'position')) {
            return true;
        }
        if ($order === 'relevance' || $order === 'position') {
            return false;
        }
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $order);
        } catch (\Throwable) {
            return false;
        }

        return $attribute && $attribute->getId() && (int) $attribute->getUsedForSortBy() === 1;
    }

    private function toPositiveInt(mixed $value): int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            return 0;
        }

        return (int) $value;
    }
}
