<?php
declare(strict_types=1);

namespace WarpSearch\Search\Console;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WarpSearch\Search\Model\Api\Client;
use WarpSearch\Search\Model\Indexer;
use WarpSearch\Search\Model\Queue\Publisher;

class ReindexCommand extends Command
{
    public function __construct(
        private readonly State $state,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Indexer $indexer,
        private readonly Publisher $publisher,
        private readonly Client $client,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('warpsearch:reindex')
            ->setDescription('Send all (or selected) products to WarpSearch')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Only this store view code')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Comma separated product ids instead of the whole catalog')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Products per request', '100')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Publish to the message queue instead of indexing in this process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('adminhtml');
        } catch (\Throwable) {
            // area already set
        }

        $storeIds = null;
        if ($code = $input->getOption('store')) {
            $storeIds = [(int) $this->storeManager->getStore($code)->getId()];
        }

        foreach ($this->storeManager->getStores() as $store) {
            if ($storeIds && !in_array((int) $store->getId(), $storeIds, true)) {
                continue;
            }
            try {
                if ($this->client->getCollection((int) $store->getId()) === null) {
                    $output->writeln(sprintf('<error>Store %s: WarpSearch collection not found or key has no role on it.</error>', $store->getCode()));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<comment>Store %s: could not verify the collection (%s)</comment>', $store->getCode(), $e->getMessage()));
            }
        }

        $ids = $input->getOption('ids')
            ? array_map('intval', explode(',', (string) $input->getOption('ids')))
            : array_map('intval', $this->collectionFactory->create()->getAllIds());
        $batch = max(1, (int) $input->getOption('batch'));
        $output->writeln(sprintf('%d products', count($ids)));

        $failed = 0;
        foreach (array_chunk($ids, $batch) as $i => $chunk) {
            if ($input->getOption('queue')) {
                $this->publisher->enqueue($chunk);
                $this->publisher->flush();
            } else {
                $failed += $this->indexer->reindex($chunk, $storeIds, false);
            }
            $output->writeln(sprintf('batch %d done (%d ids)', $i + 1, count($chunk)));
        }

        $output->writeln($failed ? "<error>$failed documents failed</error>" : '<info>Done</info>');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
