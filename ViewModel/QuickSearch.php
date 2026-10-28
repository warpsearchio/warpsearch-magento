<?php
declare(strict_types=1);

namespace WarpSearch\Search\ViewModel;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use WarpSearch\Search\Model\Config;

class QuickSearch implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly Json $json
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->quickSearchEnabled((int) $this->storeManager->getStore()->getId());
    }

    public function getJsConfig(): string
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return $this->json->serialize([
            'suggestUrl' => $this->url->getUrl('warpsearch/search/suggest'),
            'minChars' => $this->config->minChars($storeId),
            'debounce' => $this->config->debounceMs($storeId),
            'resultsUrl' => $this->url->getUrl('catalogsearch/result'),
        ]);
    }
}
