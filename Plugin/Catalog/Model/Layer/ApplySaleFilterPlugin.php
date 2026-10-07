<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\ActiveSaleFilter;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\LayerProductIds;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Customer\Model\Context as CustomerContext;
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
        private readonly LoggerInterface $logger,
        private readonly LayerProductIds $layerProductIds,
        private readonly ActiveSaleFilter $activeSaleFilter
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

        $value = $this->config->normalizeFilterValue($this->request->getParam(Config::FILTER_REQUEST_VAR));
        if ($value === null) {
            return $collection;
        }
        if ($value === Config::VALUE_NOT_ON_SALE && !$this->config->isShowNotOnSaleOption()) {
            return $collection;
        }

        try {
            $allowedIds = $this->resolveFilteredIds($subject, $value);

            $collection->setFlag(self::ITEMS_FLAG, $allowedIds);
            $collection->setFlag(self::COUNT_FLAG, count($allowedIds));
            $this->activeSaleFilter->setAllowedIds($allowedIds);

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
        $layerIds = $this->layerProductIds->getIds($subject);
        if ($layerIds === []) {
            return [];
        }

        $onSale = array_flip($this->fetchOnSaleIds());
        $wantOnSale = $value === Config::VALUE_ON_SALE;

        return array_values(array_filter(
            $layerIds,
            static fn (int $id): bool => isset($onSale[$id]) === $wantOnSale
        ));
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
