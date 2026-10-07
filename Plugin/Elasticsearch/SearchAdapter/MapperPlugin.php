<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Elasticsearch\SearchAdapter;

use Magento\Elasticsearch\ElasticAdapter\SearchAdapter\Mapper;
use Magento\Framework\Search\RequestInterface;
use Panth\SaleFilter\Model\ActiveSaleFilter;
use Panth\SaleFilter\Model\LayerProductIds;

class MapperPlugin
{
    private const CONTAINERS = [
        LayerProductIds::REQUEST_CATALOG,
        LayerProductIds::REQUEST_SEARCH,
    ];

    public function __construct(
        private readonly ActiveSaleFilter $activeSaleFilter
    ) {
    }

    public function afterBuildQuery(Mapper $subject, $result, RequestInterface $request)
    {
        $ids = $this->activeSaleFilter->getAllowedIds();
        if ($ids === null || !is_array($result) || !in_array($request->getName(), self::CONTAINERS, true)) {
            return $result;
        }

        $filter = ['ids' => ['values' => array_map('strval', $ids === [] ? [0] : $ids)]];
        $query = $result['body']['query'] ?? [];

        if (isset($query['bool']) && is_array($query['bool'])) {
            $existing = $query['bool']['filter'] ?? [];
            if (!is_array($existing) || ($existing !== [] && !array_is_list($existing))) {
                $existing = [$existing];
            }
            $existing[] = $filter;
            $result['body']['query']['bool']['filter'] = $existing;

            return $result;
        }

        $bool = ['filter' => [$filter]];
        if ($query !== []) {
            $bool['must'] = [$query];
        }
        $result['body']['query'] = ['bool' => $bool];

        return $result;
    }
}
