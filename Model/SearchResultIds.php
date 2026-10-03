<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Framework\Api\FilterBuilderFactory;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\Search\SearchInterface;

class SearchResultIds
{
    private const REQUEST_NAME = 'quick_search_container';
    private const MAX_RESULTS  = 10000;

    private array $cache = [];

    public function __construct(
        private readonly SearchInterface $search,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilderFactory $filterBuilderFactory,
        private readonly ProductVisibility $productVisibility
    ) {
    }

    public function getIds(string $queryText): array
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return [];
        }
        if (isset($this->cache[$queryText])) {
            return $this->cache[$queryText];
        }

        $criteriaBuilder = $this->searchCriteriaBuilderFactory->create();
        $filterBuilder   = $this->filterBuilderFactory->create();

        $criteriaBuilder->addFilter(
            $filterBuilder->setField('search_term')->setValue($queryText)->create()
        );
        $criteriaBuilder->addFilter(
            $filterBuilder->setField('visibility')
                ->setValue($this->productVisibility->getVisibleInSearchIds())
                ->create()
        );

        $criteria = $criteriaBuilder->create();
        $criteria->setRequestName(self::REQUEST_NAME);
        $criteria->setSortOrders([]);
        $criteria->setPageSize(self::MAX_RESULTS);
        $criteria->setCurrentPage(0);

        $ids = [];
        foreach ($this->search->search($criteria)->getItems() as $item) {
            $id = (int) $item->getId();
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $this->cache[$queryText] = array_values($ids);

        return $this->cache[$queryText];
    }
}
