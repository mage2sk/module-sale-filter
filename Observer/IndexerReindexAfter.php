<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Observer;

use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\SaleFilter\Model\Config;
use Psr\Log\LoggerInterface;

class IndexerReindexAfter implements ObserverInterface
{
    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->cacheManager->clean([
                Config::CACHE_TAG,
                'block_html',
                'full_page',
            ]);
            $this->logger->debug('[Panth_SaleFilter] post-indexer cache clean completed');
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_SaleFilter] post-indexer cache clean failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
