<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Block\LayeredNavigation;

use Magento\Catalog\Model\Layer\Filter\FilterInterface;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\StoreManagerInterface;
use Panth\SaleFilter\Model\Config;

/**
 * Cache-aware block wrapping the sale-filter renderer output.
 *
 * The block composes a cache key from store, website, customer group,
 * currency, the current category and the active sale-filter request
 * parameter so that the FPC can distinguish each unique filter state.
 * It also publishes identity tags for Product, CatalogRule and the
 * module Config entity so purges on those entities invalidate this
 * block's cached HTML.
 */
class FilterRenderer extends Template implements IdentityInterface
{
    /**
     * Customer group in the cache key comes from {@see HttpContext}
     * (populated by Magento's `customer-app-action-executeController-context-plugin`)
     * rather than the customer session because
     * {@see \Magento\Customer\Model\Layout\DepersonalizePlugin} wipes the
     * session to guest BEFORE cacheable pages render. Using HTTP Context
     * keeps the block cache key aligned with the FPC's X-Magento-Vary
     * hash, so each customer group reliably sees its own rendered filter.
     */
    public function __construct(
        Template\Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpContext $httpContext,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly LayerResolver $layerResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return the filter instance bound via layout XML arguments.
     *
     * @return FilterInterface|null
     */
    public function getFilter(): ?FilterInterface
    {
        $filter = $this->getData('filter');

        return $filter instanceof FilterInterface ? $filter : null;
    }

    /**
     * Return the configured filter label.
     *
     * @return string
     */
    public function getFilterLabel(): string
    {
        return $this->config->getFilterLabel();
    }

    /**
     * Whether the product count should be shown next to the filter option.
     *
     * @return bool
     */
    public function isShowCount(): bool
    {
        return $this->config->isShowCount();
    }

    /**
     * Compose cache key components varying per store, website, customer
     * group, currency, current category and active sale-filter param.
     *
     * @return array
     */
    public function getCacheKeyInfo(): array
    {
        $store    = $this->storeManager->getStore();
        $category = $this->layerResolver->get()->getCurrentCategory();
        $categoryId = $category ? (int) $category->getId() : 0;

        return array_merge(parent::getCacheKeyInfo(), [
            'MAGE2SK_SALEFILTER',
            (int) $store->getId(),
            (int) $store->getWebsiteId(),
            (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP),
            (string) $store->getCurrentCurrencyCode(),
            $categoryId,
            (string) ($this->request->getParam(Config::FILTER_REQUEST_VAR) ?? '0'),
        ]);
    }

    /**
     * Return cache tags this block depends on so FPC purges on related
     * entity changes invalidate the cached HTML.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        // `cat_p` — tags every FPC entry that contains product data, so the
        // sidebar block is evicted whenever a relevant product changes.
        // `panth_salefilter` — our own tag, cleaned by the indexer and by
        // the upstream-event observer after any catalog rule apply or
        // product save that could shift the on-sale flag.
        //
        // We intentionally do NOT emit the generic `price` tag
        // (`CatalogRule::getIdentities()` returns `['price']`). Cleaning it
        // on every rule change would over-evict the FPC because `price` is
        // stamped on many unrelated entries.
        return [
            CatalogProduct::CACHE_TAG,
            Config::CACHE_TAG,
        ];
    }
}
