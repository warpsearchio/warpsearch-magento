<?php
declare(strict_types=1);

namespace WarpSearch\Search\Plugin;

use Magento\Catalog\Model\Product\Action;
use Psr\Log\LoggerInterface;
use WarpSearch\Search\Model\IndexScheduler;

/**
 * Mass "Update attributes", mass status change and website assign/unassign bypass catalog_product_save_after.
 * updateWebsites only exists on the model (not the resource model), so the plugin is on Product\Action.
 */
class MassActionPlugin
{
    public function __construct(private readonly IndexScheduler $scheduler, private readonly LoggerInterface $logger)
    {
    }

    public function afterUpdateAttributes(Action $subject, $result, $productIds, $attrData, $storeId)
    {
        $this->schedule((array) $productIds);
        return $result;
    }

    public function afterUpdateWebsites(Action $subject, $result, $productIds, $websiteIds, $type)
    {
        $this->schedule((array) $productIds);
        return $result;
    }

    private function schedule(array $ids): void
    {
        try {
            $this->scheduler->schedule($ids);
        } catch (\Throwable $e) {
            $this->logger->error('WarpSearch could not schedule indexing: ' . $e->getMessage());
        }
    }
}
