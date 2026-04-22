<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Console\Command;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento panth:salefilter:status
 *
 * Prints a table of on-sale product counts broken down by
 * (website, customer group) — useful for verifying the indexer ran and
 * seeing scope-level coverage at a glance.
 */
class StatusCommand extends Command
{
    public function __construct(
        private readonly AppState $appState,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly ResourceConnection $resource,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:salefilter:status');
        $this->setDescription('Show Panth Sale Filter indexer status — on-sale count per website x customer group.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // already set — fine
        }

        try {
            $websites = $this->websiteRepository->getList();
            $groups = $this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems();
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_salefilter_product_index');

            $rows = [];
            foreach ($websites as $website) {
                if ((int) $website->getId() === 0) {
                    // Skip the admin website.
                    continue;
                }
                foreach ($groups as $group) {
                    $count = (int) $connection->fetchOne(
                        $connection->select()
                            ->from($table, new \Zend_Db_Expr('COUNT(*)'))
                            ->where('website_id = ?', (int) $website->getId())
                            ->where('customer_group_id = ?', (int) $group->getId())
                            ->where('is_on_sale = ?', 1)
                    );
                    $rows[] = [
                        $website->getName() . ' (#' . $website->getId() . ')',
                        $group->getCode() . ' (#' . $group->getId() . ')',
                        $count,
                    ];
                }
            }

            if ($rows === []) {
                $output->writeln('<comment>No websites or customer groups found.</comment>');
                return Command::SUCCESS;
            }

            $tableHelper = new Table($output);
            $tableHelper->setHeaders(['Website', 'Customer Group', 'On Sale Count']);
            $tableHelper->setRows($rows);
            $tableHelper->render();
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to read status: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
