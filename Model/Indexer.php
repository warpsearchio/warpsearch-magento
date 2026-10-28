<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model;

use Magento\Bundle\Model\ResourceModel\Selection as BundleSelection;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use WarpSearch\Search\Model\Api\Client;
use WarpSearch\Search\Model\Document\DocumentMapper;
use WarpSearch\Search\Model\Document\FieldNames;

/**
 * Upserts or deletes the documents of the given products in every enabled store view.
 * Writes are idempotent (upsert/delete by document id), so queue redelivery is safe.
 */
class Indexer
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly ProductExtractor $extractor,
        private readonly Client $client,
        private readonly Config $config,
        private readonly ConfigurableResource $configurable,
        private readonly BundleSelection $bundle,
        private readonly GroupedLink $grouped,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Parents of composite products embed their children's data, so a changed child re-indexes its parents too.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function withParents(array $ids): array
    {
        $all = array_fill_keys(array_map('intval', $ids), true);
        foreach ($ids as $id) {
            foreach ([$this->configurable->getParentIdsByChild($id), $this->bundle->getParentIdsByChild($id), $this->grouped->getParentIdsByChild($id, GroupedLink::LINK_TYPE_GROUPED)] as $parents) {
                foreach ((array) $parents as $parent) {
                    $all[(int) $parent] = true;
                }
            }
        }
        return array_keys($all);
    }

    /**
     * @param int[] $ids
     * @param int[]|null $onlyStoreIds
     * @return int number of failed items
     */
    public function reindex(array $ids, ?array $onlyStoreIds = null, bool $includeParents = true): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($includeParents) {
            $ids = $this->withParents($ids);
        }
        $failed = 0;
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (($onlyStoreIds !== null && !in_array($storeId, $onlyStoreIds, true)) || !$store->getIsActive() || !$this->config->isEnabled($storeId)) {
                continue;
            }
            foreach (array_chunk($ids, $this->config->batchSize()) as $chunk) {
                $failed += $this->reindexChunk($chunk, $store);
            }
        }
        return $failed;
    }

    /** @param int[] $chunk */
    private function reindexChunk(array $chunk, StoreInterface $store): int
    {
        $storeId = (int) $store->getId();
        $index = $this->config->indexName($storeId);
        $mapper = new DocumentMapper(new FieldNames($this->config->textAnalysis()));

        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            $data = $this->extractor->extract($chunk, $store);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        $actions = [];
        foreach ($data['products'] as $product) {
            [$id, $doc] = $mapper->map($index, $product);
            $actions[] = ['id' => $id, 'doc' => $doc];
        }
        foreach ($data['remove'] as $productId) {
            $actions[] = ['id' => DocumentMapper::documentId($index, $productId, $storeId), 'doc' => null];
        }

        // every write is committed at once (refresh=true) so shoppers see changes quickly; commits are billed, which
        // is why callers batch ids (queue consumer: <= batch_size per request)
        $failures = $this->client->bulk($storeId, $actions, true);
        foreach ($failures as $f) {
            $this->logger->error(sprintf('WarpSearch could not write %s: %s %s', $f['id'], $f['type'], $f['reason']));
        }
        return count($failures);
    }
}
