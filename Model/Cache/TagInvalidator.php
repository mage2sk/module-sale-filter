<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Cache;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Framework\App\Cache\Frontend\Pool as CacheFrontendPool;
use Panth\SaleFilter\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Surgical, multi-backend cache-tag invalidation.
 *
 * Why this exists:
 *   Magento can host the `default` cache frontend and the `page_cache`
 *   (FPC) frontend on *different* backends / Redis databases. Cleaning tags
 *   via `\Magento\Framework\App\CacheInterface::clean()` only touches the
 *   default frontend's backend — FPC entries on a separate Redis DB keep
 *   serving stale HTML. Cleaning by cache *type* (`full_page`) via
 *   `CacheManager::clean()` is the opposite mistake: it evicts every FPC
 *   entry in the store, nuking hit rate for every unrelated page.
 *
 * What this class does:
 *   Iterates `Cache\Frontend\Pool` — which enumerates every configured
 *   frontend (default + page_cache + any custom frontends) — and calls
 *   `clean(CLEANING_MODE_MATCHING_ANY_TAG, $tags)` on each. Every backend
 *   gets a chance to evict its own tagged entries.
 *
 * Tag set: `catalog_product`, `catalog_rule`, and `panth_salefilter`. Our
 * FilterRenderer block emits exactly these three via `getIdentities()`, so
 * the invalidation is surgical: only FPC / block HTML entries that actually
 * render our filter are evicted. Everything else stays warm.
 */
class TagInvalidator
{
    /**
     * Tags to clean across every cache frontend.
     *
     * We use only `cat_p` and our own `panth_salefilter` tag. `cat_p`
     * already tags every FPC entry that contains catalog products (category
     * listings, PDPs, widgets) so a catalog-rule change — which shifts
     * product prices — evicts the right entries via `cat_p` even though we
     * don't emit the generic `price` tag ourselves. Adding `price` would
     * over-evict on unrelated price writes.
     *
     * @var string[]
     */
    private const TAGS = [
        CatalogProduct::CACHE_TAG,
        Config::CACHE_TAG,
    ];

    public function __construct(
        private readonly CacheFrontendPool $cacheFrontendPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Clean tagged entries across every configured cache frontend.
     *
     * Defensive: a single backend failure must not stop the others.
     *
     * @return void
     */
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
