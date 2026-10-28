<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model;

use WarpSearch\Search\Model\Queue\Publisher;

/** Entry point for observers/plugins: queue (default) or index synchronously. */
class IndexScheduler
{
    public function __construct(
        private readonly Config $config,
        private readonly Publisher $publisher,
        private readonly Indexer $indexer
    ) {
    }

    /** @param int[] $productIds */
    public function schedule(array $productIds): void
    {
        $productIds = array_filter(array_map('intval', $productIds));
        if ($productIds === []) {
            return;
        }
        if ($this->config->useQueue()) {
            $this->publisher->enqueue($productIds);
            return;
        }
        $this->indexer->reindex($productIds);
    }
}
