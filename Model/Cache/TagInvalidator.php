<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Cache;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Framework\App\Cache\Frontend\Pool as CacheFrontendPool;
use Panth\SaleFilter\Model\Config;
use Psr\Log\LoggerInterface;

class TagInvalidator
{
    private const TAGS = [
        CatalogProduct::CACHE_TAG,
        Config::CACHE_TAG,
    ];

    public function __construct(
        private readonly CacheFrontendPool $cacheFrontendPool,
        private readonly LoggerInterface $logger
    ) {
    }

    public function invalidate(): void
    {
        foreach ($this->cacheFrontendPool as $frontend) {
            try {
                $frontend->clean(\Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, self::TAGS);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[panth_salefilter] Cache frontend clean failed: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }
}
