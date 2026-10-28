<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Collects product ids during a request and publishes them once, in chunks, so a mass action or import that touches
 * thousands of products produces a handful of queue messages instead of one per product.
 */
class Publisher
{
    public const TOPIC = 'warpsearch.product.index';

    /** @var array<int,true> */
    private array $pending = [];
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly int $chunkSize = 200,
        private readonly bool $flushOnShutdown = true
    ) {
    }

    /** @param int[] $productIds */
    public function enqueue(array $productIds): void
    {
        foreach ($productIds as $id) {
            if ((int) $id > 0) {
                $this->pending[(int) $id] = true;
            }
        }
        if ($this->flushOnShutdown && !$this->shutdownRegistered && $this->pending !== []) {
            $this->shutdownRegistered = true;
            register_shutdown_function(fn () => $this->flush());
        }
    }

    public function flush(): int
    {
        $ids = array_keys($this->pending);
        $this->pending = [];
        $messages = 0;
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $this->publisher->publish(self::TOPIC, json_encode(['product_ids' => $chunk], JSON_THROW_ON_ERROR));
            $messages++;
        }
        return $messages;
    }
}
