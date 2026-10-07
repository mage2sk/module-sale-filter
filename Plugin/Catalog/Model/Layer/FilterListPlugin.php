<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilter;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilterFactory;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\FilterList;
use Psr\Log\LoggerInterface;

class FilterListPlugin
{
    private \WeakMap $filters;

    public function __construct(
        private readonly Config $config,
        private readonly SaleFilterFactory $filterFactory,
        private readonly LoggerInterface $logger
    ) {
        $this->filters = new \WeakMap();
    }

    public function afterGetFilters(
        FilterList $subject,
        array $result,
        Layer $layer
    ): array {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        try {
            if (!isset($this->filters[$layer])) {
                $this->filters[$layer] = $this->filterFactory->create(['layer' => $layer]);
            }
            $saleFilter = $this->filters[$layer];

            $result = array_values($result);
            $position = $this->config->getPosition();
            $index = count($result);
            foreach ($result as $i => $filter) {
                if ($this->resolveFilterPosition($filter) > $position) {
                    $index = $i;
                    break;
                }
            }
            array_splice($result, $index, 0, [$saleFilter]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Panth_SaleFilter] Failed to append sale filter to filter list',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function resolveFilterPosition(mixed $filter): int
    {
        if (!is_object($filter) || !method_exists($filter, 'getAttributeModel')) {
            return PHP_INT_MIN;
        }

        try {
            $attribute = $filter->getAttributeModel();
        } catch (\Throwable) {
            return PHP_INT_MIN;
        }

        return $attribute ? (int) $attribute->getPosition() : PHP_INT_MIN;
    }
}
