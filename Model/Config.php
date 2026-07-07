<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const XML_PATH_ENABLED                    = 'panth_salefilter/general/enabled';
    public const XML_PATH_FILTER_LABEL               = 'panth_salefilter/general/filter_label';
    public const XML_PATH_OPTION_LABEL_ON_SALE       = 'panth_salefilter/general/option_label_on_sale';
    public const XML_PATH_SHOW_NOT_ON_SALE_OPTION    = 'panth_salefilter/general/show_not_on_sale_option';
    public const XML_PATH_OPTION_LABEL_NOT_ON_SALE   = 'panth_salefilter/general/option_label_not_on_sale';
    public const XML_PATH_SHOW_COUNT                 = 'panth_salefilter/general/show_count';
    public const XML_PATH_INCLUDE_SPECIAL_PRICES     = 'panth_salefilter/general/include_special_prices';
    public const XML_PATH_INCLUDE_CATALOG_RULES      = 'panth_salefilter/general/include_catalog_rules';
    public const XML_PATH_POSITION                   = 'panth_salefilter/general/position';

    public const FILTER_REQUEST_VAR = 'sale_filter';
    public const CACHE_TAG          = 'panth_salefilter';

    public const VALUE_ON_SALE     = 1;
    public const VALUE_NOT_ON_SALE = 0;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    public function getFilterLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_FILTER_LABEL,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        return $value !== '' ? $value : 'Sale Status';
    }

    public function getOnSaleOptionLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPTION_LABEL_ON_SALE,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        return $value !== '' ? $value : 'On Sale';
    }

    public function isShowNotOnSaleOption(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_NOT_ON_SALE_OPTION,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    public function getNotOnSaleOptionLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPTION_LABEL_NOT_ON_SALE,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        return $value !== '' ? $value : 'Regular Price';
    }

    public function isShowCount(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_COUNT,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    public function isIncludeSpecialPrices(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_SPECIAL_PRICES,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    public function isIncludeCatalogRules(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_CATALOG_RULES,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    public function getPosition(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_POSITION,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        if ($value === null || $value === '') {
            return 100;
        }

        return (int) $value;
    }

    protected function resolveStoreId(?int $storeId): int
    {
        if ($storeId !== null) {
            return $storeId;
        }

        return (int) $this->storeManager->getStore()->getId();
    }
}
