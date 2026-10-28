<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Turns Magento products of one store view into plain arrays for DocumentMapper.
 *
 * Eligibility (otherwise the product is deleted from the index for that store view):
 *  - assigned to the store's website, status enabled, visibility "Search" or "Catalog, Search"
 *  - in stock, unless out-of-stock products are displayed/indexed. Stock is read from the stock items, which are
 *    written in the save request (MSI keeps them in sync for the default source), not from the stock index, which
 *    lags behind with "Update by Schedule" indexers; a composite is in stock when its own flag is set and an
 *    enabled child is in stock
 * Composite products: configurable/bundle/grouped parents carry their children's SKUs and names as searchable text
 * and the min/max price Magento computes for the composite; children are only indexed themselves when visible.
 */
class ProductExtractor
{
    /** @var array<string,string> category name cache, key "store:id" */
    private array $categoryNames = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly Config $config
    ) {
    }

    /**
     * @param int[] $ids
     * @return array{products: array<int,array<string,mixed>>, remove: int[]} removable = ids to delete for this store
     */
    public function extract(array $ids, StoreInterface $store): array
    {
        $storeId = (int) $store->getId();
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect('*')
            ->addCategoryIds();

        /** @var Product[] $loaded */
        $loaded = [];
        foreach ($collection as $product) {
            $loaded[(int) $product->getId()] = $product;
        }
        $children = [];
        foreach ($loaded as $id => $product) {
            $children[$id] = $this->children($product, $storeId);
        }
        $stock = $this->stockFlags(array_merge(array_keys($loaded), ...array_map(static fn ($c) => array_keys($c ?? []), array_values($children))), $storeId);

        $products = [];
        foreach ($loaded as $id => $product) {
            $inStock = $this->inStock($id, $children[$id], $stock);
            if (!$this->isEligible($product, $store, $inStock)) {
                continue;
            }
            $products[$id] = $this->toArray($product, $store, $children[$id], $inStock, $stock);
        }

        // ids that are missing (deleted), disabled, invisible or out of stock are removed from the index
        $remove = array_values(array_diff($ids, array_keys($products)));
        return ['products' => array_values($products), 'remove' => $remove];
    }

    private function isEligible(Product $product, StoreInterface $store, bool $inStock): bool
    {
        $storeId = (int) $store->getId();
        if (!in_array((int) $store->getWebsiteId(), array_map('intval', $product->getWebsiteIds()), true)) {
            return false;
        }
        if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
            return false;
        }
        if (!in_array((int) $product->getVisibility(), [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH], true)) {
            return false;
        }
        return $inStock || $this->config->indexOutOfStock($storeId);
    }

    /**
     * @param array<int,array{sku:string,name:string,enabled:bool}>|null $children null for non-composite products
     * @param array<int,bool> $stock
     */
    private function inStock(int $productId, ?array $children, array $stock): bool
    {
        if (!($stock[$productId] ?? false)) {
            return false;
        }
        if ($children === null) {
            return true;
        }
        foreach ($children as $childId => $child) {
            if ($child['enabled'] && ($stock[$childId] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * In-stock flag per product from cataloginventory_stock_item; products whose stock is not managed count as in stock.
     *
     * @param int[] $ids
     * @return array<int,bool>
     */
    private function stockFlags(array $ids, int $storeId): array
    {
        if ($ids === []) {
            return [];
        }
        $manageByConfig = (bool) $this->stockConfiguration->getManageStock($storeId);
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('cataloginventory_stock_item'), ['product_id', 'is_in_stock', 'manage_stock', 'use_config_manage_stock'])
            ->where('product_id IN (?)', array_values(array_unique($ids)));
        $flags = [];
        foreach ($connection->fetchAll($select) as $row) {
            $managed = (int) $row['use_config_manage_stock'] ? $manageByConfig : (bool) (int) $row['manage_stock'];
            $flags[(int) $row['product_id']] = !$managed || (int) $row['is_in_stock'] === 1;
        }
        return $flags;
    }

    /** @return array<string,mixed> */
    /**
     * @param array<int,array{sku:string,name:string,enabled:bool,price:?float}>|null $children
     * @param array<int,bool> $stock
     */
    private function toArray(Product $product, StoreInterface $store, ?array $children, bool $inStock, array $stock): array
    {
        $storeId = (int) $store->getId();
        $childSkus = array_values(array_column($children ?? [], 'sku'));
        $childNames = array_values(array_unique(array_column($children ?? [], 'name')));

        $categoryIds = array_map('intval', (array) $product->getCategoryIds());
        $row = [
            'product_id' => (int) $product->getId(),
            'store_id' => $storeId,
            'type' => $product->getTypeId(),
            'sku' => (string) $product->getSku(),
            'name' => (string) $product->getName(),
            'description' => (string) $product->getData('description'),
            'short_description' => (string) $product->getData('short_description'),
            'visibility' => (int) $product->getVisibility(),
            'in_stock' => $inStock,
            'category_ids' => $categoryIds,
            'category_names' => $this->categoryNamesFor($categoryIds, $storeId),
            'child_skus' => $childSkus,
            'child_names' => $childNames,
            'attributes' => $this->attributes($product),
            'created_at' => $product->getCreatedAt(),
            'updated_at' => $product->getUpdatedAt(),
        ];
        [$row['price_min'], $row['price_max']] = $this->prices($product, $children, $stock);
        return $row;
    }

    /** @return array<int,array{sku:string,name:string,enabled:bool,price:?float}>|null child id => data, null if not composite */
    private function children(Product $product, int $storeId): ?array
    {
        if (!in_array($product->getTypeId(), ['configurable', 'bundle', 'grouped'], true)) {
            return null;
        }
        $childIds = [];
        // getChildrenIds returns [optionId/group => [childId => childId]] for all three composite types
        foreach ($product->getTypeInstance()->getChildrenIds($product->getId(), false) as $group) {
            foreach ($group as $childId) {
                $childIds[(int) $childId] = true;
            }
        }
        if ($childIds === []) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)->addIdFilter(array_keys($childIds))->addAttributeToSelect(['name', 'sku', 'status', 'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id']);
        $children = [];
        foreach ($collection as $child) {
            $children[(int) $child->getId()] = [
                'sku' => (string) $child->getSku(),
                'name' => (string) $child->getName(),
                'enabled' => (int) $child->getStatus() === Status::STATUS_ENABLED,
                'price' => $this->finalPrice($child),
            ];
        }
        return $children;
    }

    /**
     * Searchable attributes become text, filterable-in-search attributes become keywords. Select/multiselect values
     * are indexed as their store-view labels. Prices, media and system attributes are skipped.
     *
     * @return array<int,array{code:string,values:string[],searchable:bool,filterable:bool}>
     */
    private function attributes(Product $product): array
    {
        $skip = ['name', 'sku', 'description', 'short_description', 'price', 'special_price', 'cost', 'status', 'visibility',
            'url_key', 'image', 'small_image', 'thumbnail', 'media_gallery', 'category_ids', 'tier_price', 'quantity_and_stock_status', 'tax_class_id'];
        $out = [];
        foreach ($product->getAttributes() as $attribute) {
            $code = $attribute->getAttributeCode();
            if (in_array($code, $skip, true)) {
                continue;
            }
            $searchable = (bool) $attribute->getIsSearchable();
            $filterable = (bool) $attribute->getIsFilterableInSearch() || (bool) $attribute->getIsFilterable();
            if (!$searchable && !$filterable) {
                continue;
            }
            $input = $attribute->getFrontendInput();
            if (in_array($input, ['select', 'multiselect', 'boolean'], true)) {
                $text = $product->getAttributeText($code);
                $values = is_array($text) ? $text : ($text ? [$text] : []);
            } else {
                $raw = $product->getData($code);
                $values = is_scalar($raw) && $raw !== '' ? [(string) $raw] : [];
            }
            if ($values !== []) {
                $out[] = ['code' => $code, 'values' => array_map('strval', $values), 'searchable' => $searchable, 'filterable' => $filterable];
            }
        }
        return $out;
    }

    /**
     * Min/max final price for the "not logged in" customer group (the caller runs under store emulation).
     * Configurable and grouped products report the range of their enabled (in stock, if any are) children, computed
     * from the children themselves: their price models' min/max read Magento's price index, which lags behind with
     * "Update by Schedule" and does not give the maximum. Bundles use the bundle price model's range (none while it is not in the price index).
     *
     * @param array<int,array{sku:string,name:string,enabled:bool,price:?float}>|null $children
     * @param array<int,bool> $stock
     * @return array{0:?float,1:?float}
     */
    private function prices(Product $product, ?array $children, array $stock): array
    {
        if ($children && in_array($product->getTypeId(), ['configurable', 'grouped'], true)) {
            $all = [];
            $inStock = [];
            foreach ($children as $childId => $child) {
                if ($child['enabled'] && $child['price'] !== null) {
                    $all[] = $child['price'];
                    if ($stock[$childId] ?? false) {
                        $inStock[] = $child['price'];
                    }
                }
            }
            $range = $inStock ?: $all;
            if ($range) {
                return [min($range), max($range)];
            }
        }
        try {
            $final = $product->getPriceInfo()->getPrice('final_price');
            $range = [(float) $final->getMinimalPrice()->getValue(), (float) $final->getMaximalPrice()->getValue()];
            // a dynamic-price bundle reads its selections through the price index: 0/0 means "not indexed yet"
            return $product->getTypeId() === 'bundle' && $range[1] <= 0.0 ? [null, null] : $range;
        } catch (\Throwable) {
            $price = $product->getPrice();
            return $price === null ? [null, null] : [(float) $price, (float) $price];
        }
    }

    private function finalPrice(Product $product): ?float
    {
        try {
            return (float) $product->getPriceInfo()->getPrice('final_price')->getValue();
        } catch (\Throwable) {
            return $product->getPrice() === null ? null : (float) $product->getPrice();
        }
    }

    /** @param int[] $ids @return string[] */
    private function categoryNamesFor(array $ids, int $storeId): array
    {
        $missing = array_filter($ids, fn ($id) => !isset($this->categoryNames["$storeId:$id"]));
        if ($missing) {
            $categories = $this->categoryCollectionFactory->create();
            $categories->setStoreId($storeId)->addAttributeToSelect('name')->addIdFilter($missing);
            foreach ($categories as $category) {
                $this->categoryNames["$storeId:" . $category->getId()] = (string) $category->getName();
            }
            foreach ($missing as $id) {
                $this->categoryNames["$storeId:$id"] ??= '';
            }
        }
        return array_values(array_filter(array_map(fn ($id) => $this->categoryNames["$storeId:$id"] ?? '', $ids)));
    }
}
