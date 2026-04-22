<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Single source of truth for Panth Sale Filter admin configuration.
 *
 * Every other class in this module MUST read admin config values through this
 * class. Do not scatter direct ScopeConfigInterface calls across observers,
 * plugins, blocks, or models - route them through here so there is exactly
 * one definition of each XML path and one place to adjust fallbacks / scope
 * resolution.
 *
 * All getters are store-scoped and accept a nullable int $storeId which, when
 * null, resolves to the currently active store via StoreManagerInterface.
 */
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

    /**
     * Whether the Sale Filter module is enabled for the given store.
     *
     * @param int|null $storeId Null resolves to the current store.
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    /**
     * Label shown for the Sale filter in the storefront layered nav.
     *
     * Falls back to "On Sale" when the admin value is empty.
     *
     * @param int|null $storeId Null resolves to the current store.
     * @return string
     */
    public function getFilterLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_FILTER_LABEL,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        return $value !== '' ? $value : 'Sale Status';
    }

    /**
     * Label shown for the "on sale" option inside the filter.
     *
     * Falls back to "On Sale" when the admin value is empty.
     *
     * @param int|null $storeId Null resolves to the current store.
     */
    public function getOnSaleOptionLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPTION_LABEL_ON_SALE,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        return $value !== '' ? $value : 'On Sale';
    }

    /**
     * Whether the filter should expose a second option for products NOT on sale.
     */
    public function isShowNotOnSaleOption(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_NOT_ON_SALE_OPTION,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    /**
     * Label shown for the "not on sale" option when the toggle is enabled.
     *
     * Falls back to "Regular Price" when empty.
     */
    public function getNotOnSaleOptionLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPTION_LABEL_NOT_ON_SALE,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );

        return $value !== '' ? $value : 'Regular Price';
    }

    /**
     * Whether the filter should display a product count next to the option.
     *
     * @param int|null $storeId Null resolves to the current store.
     * @return bool
     */
    public function isShowCount(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_COUNT,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    /**
     * Whether products on special price should count as "on sale".
     *
     * @param int|null $storeId Null resolves to the current store.
     * @return bool
     */
    public function isIncludeSpecialPrices(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_SPECIAL_PRICES,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    /**
     * Whether products matched by active catalog price rules should count as "on sale".
     *
     * @param int|null $storeId Null resolves to the current store.
     * @return bool
     */
    public function isIncludeCatalogRules(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_CATALOG_RULES,
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId($storeId)
        );
    }

    /**
     * Sort position of the Sale filter in the layered navigation.
     *
     * Falls back to 100 when the admin value is empty or not numeric.
     *
     * @param int|null $storeId Null resolves to the current store.
     * @return int
     */
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

    /**
     * Resolve the effective store id: the argument when non-null, otherwise the current store.
     *
     * @param int|null $storeId
     * @return int
     */
    protected function resolveStoreId(?int $storeId): int
    {
        if ($storeId !== null) {
            return $storeId;
        }

        return (int) $this->storeManager->getStore()->getId();
    }
}
