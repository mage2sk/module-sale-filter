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

class FilterRenderer extends Template implements IdentityInterface
{
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

    public function getFilter(): ?FilterInterface
    {
        $filter = $this->getData('filter');

        return $filter instanceof FilterInterface ? $filter : null;
    }

    public function getFilterLabel(): string
    {
        return $this->config->getFilterLabel();
    }

    public function isShowCount(): bool
    {
        return $this->config->isShowCount();
    }

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

    public function getIdentities(): array
    {
        return [
            CatalogProduct::CACHE_TAG,
            Config::CACHE_TAG,
        ];
    }
}
