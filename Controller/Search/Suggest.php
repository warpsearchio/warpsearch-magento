<?php
declare(strict_types=1);

namespace WarpSearch\Search\Controller\Search;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use WarpSearch\Search\Model\Api\Client;
use WarpSearch\Search\Model\Api\QueryBuilder;
use WarpSearch\Search\Model\Config;
use WarpSearch\Search\Model\Document\FieldNames;

/**
 * GET /warpsearch/search/suggest?q=... Server-side proxy: the API key stays on the server, ids from WarpSearch are
 * hydrated from Magento (current price, status, visibility, URL), so a stale index can never show a disabled product.
 * Any failure answers {"ok":false} and the storefront falls back to Magento's native search.
 */
class Suggest implements HttpGetActionInterface
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly Client $client,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly Image $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();
        $q = trim(mb_substr((string) $this->request->getParam('q', ''), 0, 128));
        if (!$this->config->quickSearchEnabled($storeId) || mb_strlen($q) < $this->config->minChars($storeId)) {
            return $result->setData(['ok' => false, 'items' => []]);
        }

        try {
            $names = new FieldNames($this->config->textAnalysis());
            $limit = $this->config->maxResults($storeId);
            $body = (new QueryBuilder($names))->build($this->config->indexName($storeId), $storeId, $q, 0, $limit, !$this->config->indexOutOfStock($storeId));
            $ids = QueryBuilder::extractProductIds($this->client->search($storeId, $body, self::TIMEOUT_SECONDS, 1));

            return $result->setData(['ok' => true, 'items' => $ids ? $this->hydrate($ids, $storeId) : []]);
        } catch (\Throwable $e) {
            $this->logger->warning('WarpSearch quick search failed: ' . $e->getMessage());
            return $result->setData(['ok' => false, 'items' => []]);
        }
    }

    /** @param int[] $ids @return array<int,array<string,mixed>> */
    private function hydrate(array $ids, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(['name', 'small_image', 'url_key'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->setVisibility($this->visibility->getVisibleInSearchIds())
            ->addUrlRewrite()
            ->addFinalPrice();

        $byId = [];
        foreach ($collection as $product) {
            $byId[(int) $product->getId()] = [
                'id' => (int) $product->getId(),
                'name' => (string) $product->getName(),
                'url' => $product->getProductUrl(),
                'image' => $this->imageHelper->init($product, 'product_small_image')->getUrl(),
                // minimal final price: the composites' "from" price (their final_price is 0 for grouped and bundle)
                'price' => $this->priceCurrency->format((float) $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue(), false),
            ];
        }
        $items = [];
        foreach ($ids as $id) { // keep WarpSearch's relevance order
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }
        return $items;
    }
}
