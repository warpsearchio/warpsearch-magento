<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const P = 'warpsearch/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag('general/enabled', $storeId) && $this->apiKey($storeId) !== '';
    }

    public function apiKey(?int $storeId = null): string
    {
        $raw = (string) $this->value('general/api_key', $storeId);
        return $raw === '' ? '' : trim($this->encryptor->decrypt($raw));
    }

    public function apiUrl(): string
    {
        return rtrim((string) ($this->value('general/api_url') ?: 'https://api.warpsearch.io'), '/');
    }

    /** WarpSearch collection names match ^[a-z][a-z0-9_]*$ (see the Umbraco provider's CollectionName). */
    public function collection(?int $storeId = null): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $this->value('general/collection', $storeId))), '_');
        return ($name !== '' && ctype_lower($name[0])) ? $name : 'c_' . $name;
    }

    public function indexName(?int $storeId = null): string
    {
        return (string) ($this->value('general/index_name', $storeId) ?: 'magento');
    }

    public function textAnalysis(): string
    {
        return (string) $this->value('general/text_analysis') ?: 'standard';
    }

    public function useQueue(): bool
    {
        return $this->flag('indexing/use_queue');
    }

    public function batchSize(): int
    {
        return max(1, (int) $this->value('indexing/batch_size') ?: 100);
    }

    public function indexOutOfStock(?int $storeId = null): bool
    {
        return $this->flag('indexing/index_out_of_stock', $storeId)
            || $this->scopeConfig->isSetFlag('cataloginventory/options/show_out_of_stock', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function quickSearchEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->flag('storefront/quick_search_enabled', $storeId);
    }

    public function minChars(?int $storeId = null): int
    {
        return max(1, (int) $this->value('storefront/min_chars', $storeId) ?: 2);
    }

    public function maxResults(?int $storeId = null): int
    {
        return min(20, max(1, (int) $this->value('storefront/max_results', $storeId) ?: 6));
    }

    public function debounceMs(?int $storeId = null): int
    {
        return max(0, (int) $this->value('storefront/debounce_ms', $storeId) ?: 200);
    }

    private function value(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue(self::P . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::P . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
