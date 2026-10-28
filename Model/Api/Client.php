<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Api;

use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\HTTP\ClientFactory;
use Psr\Log\LoggerInterface;
use WarpSearch\Search\Model\Config;

/**
 * Thin client over the endpoints the Umbraco provider uses: GET v1/collections/{c}, POST compat/es/{c}/_bulk
 * (NDJSON, "index"/"delete" actions) and POST compat/es/{c}/_search. Auth is "Authorization: Bearer <key>".
 * Retries 429 and 5xx with backoff (the Umbraco provider does the same; "free plan allows 5 requests a second").
 */
class Client
{
    private const MAX_ATTEMPTS = 5;
    private const ITEM_RETRY_BUDGET_MS = 120000;
    private const ITEM_RETRY_MAX_DELAY_MS = 16000;

    public function __construct(
        private readonly ClientFactory $httpFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return array<string,mixed>|null null when the collection is not found (or the key has no role on it) */
    public function getCollection(int $storeId): ?array
    {
        [$status, $body] = $this->request($storeId, 'GET', 'v1/collections/' . rawurlencode($this->config->collection($storeId)));
        if ($status === 404) {
            return null;
        }
        $this->assertOk($status, $body);
        return json_decode($body, true) ?: [];
    }

    /**
     * Item-level 429/5xx (e.g. "warming_up" while a cold collection gets capacity, answered as HTTP 200 with
     * errors:true) are transient: only those items are resubmitted, with backoff, for up to ~2 minutes in total.
     * Other item errors are permanent and returned.
     *
     * @param array<int,array{id:string,doc:?array}> $actions doc null = delete
     * @return array<int,array{id:?string,type:?string,reason:?string}> per-item failures
     */
    public function bulk(int $storeId, array $actions, bool $refresh = true): array
    {
        $failures = [];
        $waitedMs = 0;
        for ($attempt = 1; $actions !== []; $attempt++) {
            [$permanent, $transient] = $this->bulkOnce($storeId, $actions, $refresh);
            $failures = array_merge($failures, $permanent);
            if ($transient === []) {
                break;
            }
            $delayMs = min(self::ITEM_RETRY_MAX_DELAY_MS, 1000 * (2 ** ($attempt - 1)));
            if ($waitedMs + $delayMs > self::ITEM_RETRY_BUDGET_MS) {
                foreach ($transient as $t) {
                    $failures[] = $t['failure'];
                }
                break;
            }
            $this->logger->info(sprintf('WarpSearch _bulk: %d items transiently failed (%s), retry %d', count($transient), $transient[0]['failure']['type'], $attempt));
            usleep($delayMs * 1000);
            $waitedMs += $delayMs;
            $actions = array_column($transient, 'action');
        }
        return $failures;
    }

    /**
     * @param array<int,array{id:string,doc:?array}> $actions
     * @return array{0:array<int,array{id:?string,type:?string,reason:?string}>,1:array<int,array{action:array,failure:array}>}
     *         permanent failures and transient (429/5xx) ones with their action, for a resubmit
     */
    private function bulkOnce(int $storeId, array $actions, bool $refresh): array
    {
        $ndjson = '';
        $byId = [];
        foreach ($actions as $a) {
            $byId[$a['id']] = $a;
            $ndjson .= json_encode([$a['doc'] === null ? 'delete' : 'index' => ['_id' => $a['id']]], JSON_THROW_ON_ERROR) . "\n";
            if ($a['doc'] !== null) {
                $ndjson .= json_encode($a['doc'], JSON_THROW_ON_ERROR) . "\n";
            }
        }
        $path = 'compat/es/' . rawurlencode($this->config->collection($storeId)) . '/_bulk' . ($refresh ? '?refresh=true' : '');
        [$status, $body] = $this->request($storeId, 'POST', $path, $ndjson, 'application/x-ndjson');
        $this->assertOk($status, $body);

        $result = json_decode($body, true) ?: [];
        $failures = [];
        $transient = [];
        if (!empty($result['errors'])) {
            foreach ($result['items'] ?? [] as $item) {
                $inner = is_array($item) ? reset($item) : null;
                if (empty($inner['error'])) {
                    continue;
                }
                $failure = ['id' => $inner['_id'] ?? null, 'type' => $inner['error']['type'] ?? null, 'reason' => $inner['error']['reason'] ?? null];
                $itemStatus = (int) ($inner['status'] ?? 0);
                if (($itemStatus === 429 || $itemStatus >= 500) && isset($byId[(string) $failure['id']])) {
                    $transient[] = ['action' => $byId[(string) $failure['id']], 'failure' => $failure];
                } else {
                    $failures[] = $failure;
                }
            }
        }
        return [$failures, $transient];
    }

    /**
     * Storefront callers pass a short timeout and one attempt: a shopper's request must fail fast and fall back
     * instead of holding a PHP worker through retries.
     *
     * @return array<string,mixed>
     */
    public function search(int $storeId, array $body, int $timeoutSeconds = 30, int $maxAttempts = self::MAX_ATTEMPTS): array
    {
        $path = 'compat/es/' . rawurlencode($this->config->collection($storeId)) . '/_search';
        [$status, $response] = $this->request($storeId, 'POST', $path, json_encode($body, JSON_THROW_ON_ERROR), 'application/json', $timeoutSeconds, $maxAttempts);
        $this->assertOk($status, $response);
        return json_decode($response, true) ?: [];
    }

    /** @return array{0:int,1:string} */
    private function request(
        int $storeId,
        string $method,
        string $path,
        ?string $payload = null,
        string $contentType = 'application/json',
        int $timeoutSeconds = 30,
        int $maxAttempts = self::MAX_ATTEMPTS
    ): array
    {
        $url = $this->config->apiUrl() . '/' . $path;
        for ($attempt = 1; ; $attempt++) {
            /** @var ClientInterface $http */
            $http = $this->httpFactory->create();
            $http->setTimeout($timeoutSeconds);
            $http->addHeader('Authorization', 'Bearer ' . $this->config->apiKey($storeId));
            $http->addHeader('Content-Type', $contentType);
            $http->addHeader('User-Agent', 'WarpSearch_Magento/0.1');
            try {
                $method === 'GET' ? $http->get($url) : $http->post($url, (string) $payload);
                $status = (int) $http->getStatus();
                $body = (string) $http->getBody();
            } catch (\Throwable $e) {
                $status = 0;
                $body = $e->getMessage();
            }
            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable || $attempt >= $maxAttempts) {
                return [$status, $body];
            }
            $this->logger->info(sprintf('WarpSearch %s %s -> %d, retry %d', $method, $path, $status, $attempt));
            usleep((int) (250000 * (2 ** ($attempt - 1)))); // Retry-After is not read: Magento's HTTP client hides headers cheaply
        }
    }

    private function assertOk(int $status, string $body): void
    {
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('WarpSearch request failed with %d: %s', $status, substr($body, 0, 500)));
        }
    }
}
