<?php
declare(strict_types=1);

namespace WarpSearch\Search\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use WarpSearch\Search\Model\IndexScheduler;
use WarpSearch\Search\Model\Indexer;

/**
 * catalog_product_save_after, catalog_product_delete_before/after, cataloginventory_stock_item_save_commit_after,
 * catalog_category_change_products. Never breaks the admin save: failures are logged.
 */
class ProductChanged implements ObserverInterface
{
    public function __construct(
        private readonly IndexScheduler $scheduler,
        private readonly Indexer $indexer,
        private readonly LoggerInterface $logger
    )
    {
    }

    public function execute(Observer $observer): void
    {
        try {
            $ids = [];
            if ($product = $observer->getEvent()->getData('product')) {
                $ids[] = (int) $product->getId();
            }
            if ($item = $observer->getEvent()->getData('item')) { // stock item
                $ids[] = (int) $item->getProductId();
            }
            if ($category = $observer->getEvent()->getData('category')) {
                $ids = array_merge($ids, (array) $category->getChangedProductIds());
            }
            if ($ids && $observer->getEvent()->getName() === 'catalog_product_delete_before') {
                // parent links disappear with the product, so resolve the parents now, while they still exist
                $ids = $this->indexer->withParents($ids);
            }
            $this->scheduler->schedule($ids);
        } catch (\Throwable $e) {
            $this->logger->error('WarpSearch could not schedule indexing: ' . $e->getMessage());
        }
    }
}
