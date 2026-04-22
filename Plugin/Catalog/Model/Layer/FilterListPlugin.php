<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilter;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilterFactory;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\FilterList;
use Psr\Log\LoggerInterface;

/**
 * Appends the "On Sale" filter to the layered navigation filter list.
 *
 * Runs for both category pages and search result pages, since Magento wires
 * the same FilterList class for both scenarios via DI virtualTypes — this
 * plugin does not need to distinguish between them.
 */
class FilterListPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly SaleFilterFactory $filterFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Append the SaleFilter instance to the array returned by FilterList::getFilters().
     *
     * The SaleFilter must be bound to the live Layer passed to getFilters(), so we
     * construct it via its auto-generated factory with the `layer` argument.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param FilterList $subject
     * @param array $result
     * @param Layer $layer
     * @return array
     */
    public function afterGetFilters(
        FilterList $subject,
        array $result,
        Layer $layer
    ): array {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        try {
            /** @var SaleFilter $saleFilter */
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
