<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReindexCommand extends Command
{
    private const INDEXER_ID = 'panth_salefilter_product';

    public function __construct(
        private readonly AppState $appState,
        private readonly IndexerRegistry $indexerRegistry,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:salefilter:reindex');
        $this->setDescription('Reindex the Panth Sale Filter index (full rebuild).');
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Invalidate the indexer view first so a stale "valid" flag does not skip the rebuild.'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
        }

        try {
            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);
        } catch (\Throwable $e) {
            $output->writeln('<error>Indexer "' . self::INDEXER_ID . '" not found: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('force')) {
            $indexer->invalidateView();
            $output->writeln('<comment>--force: view invalidated.</comment>');
        }

        $output->writeln('<info>Reindexing Panth Sale Filter...</info>');
        $start = microtime(true);
        try {
            $indexer->reindexAll();
        } catch (\Throwable $e) {
            $output->writeln('<error>Reindex failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $duration = number_format(microtime(true) - $start, 2);
        $output->writeln("<info>Reindex complete in {$duration}s.</info>");

        return Command::SUCCESS;
    }
}
