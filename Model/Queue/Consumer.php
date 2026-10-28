<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Queue;

use Psr\Log\LoggerInterface;
use WarpSearch\Search\Model\Indexer;

class Consumer
{
    public function __construct(private readonly Indexer $indexer, private readonly LoggerInterface $logger)
    {
    }

    public function process(string $message): void
    {
        $payload = json_decode($message, true);
        $ids = is_array($payload['product_ids'] ?? null) ? $payload['product_ids'] : [];
        if ($ids === []) {
            return;
        }
        try {
            $this->indexer->reindex($ids);
        } catch (\Throwable $e) {
            // Magento's consumer rejects the message without requeue (status "error", not retried) for an \Exception,
            // but an \Error would kill the consumer process, so wrap it. Recover with warpsearch:reindex --ids.
            $this->logger->error(sprintf('WarpSearch indexing failed for products %s: %s', implode(',', $ids), $e->getMessage()));
            throw $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
