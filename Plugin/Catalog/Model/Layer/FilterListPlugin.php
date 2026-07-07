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
    public function __construct(
        private readonly Config $config,
        private readonly SaleFilterFactory $filterFactory,
        private readonly LoggerInterface $logger
    ) {
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
            $saleFilter = $this->filterFactory->create(['layer' => $layer]);
            $result[] = $saleFilter;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Panth_SaleFilter] Failed to append sale filter to filter list',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }
}
