<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Api;

use WarpSearch\Search\Model\Document\FieldNames;

/**
 * Builds the Elasticsearch Query DSL body sent to /compat/es/{collection}/_search, modelled on the Umbraco
 * provider's SearchRequestBuilder: filter clauses plus match_bool_prefix (all words, last as prefix) on the
 * "all texts" fields weighted by relevance.
 */
class QueryBuilder
{
    public function __construct(
        private readonly FieldNames $names,
        private readonly float $boostR1 = 4.0,
        private readonly float $boostR2 = 2.0,
        private readonly float $boostR3 = 1.0
    ) {
    }

    public function build(string $index, int $storeId, string $text, int $from, int $size, bool $inStockOnly = false): array
    {
        $filter = [
            ['terms' => [FieldNames::INDEX => [$index]]],
            ['terms' => [FieldNames::STORE => [(string) $storeId]]],
        ];
        if ($inStockOnly) {
            $filter[] = ['terms' => [FieldNames::field('in_stock', FieldNames::INTEGERS) => [1]]];
        }
        $bool = ['filter' => $filter];

        if (trim($text) !== '') {
            $bool['must'] = [[
                'bool' => [
                    'should' => [
                        $this->prefix($this->names->allTexts(FieldNames::TEXT), $text, 1.0),
                        $this->prefix($this->names->allTexts(FieldNames::TEXT_R1), $text, $this->boostR1),
                        $this->prefix($this->names->allTexts(FieldNames::TEXT_R2), $text, $this->boostR2),
                        $this->prefix($this->names->allTexts(FieldNames::TEXT_R3), $text, $this->boostR3),
                    ],
                    'minimum_should_match' => 1,
                ],
            ]];
        }

        return [
            'query' => ['bool' => $bool],
            'from' => $from,
            'size' => $size,
            'track_total_hits' => true,
            '_source' => [FieldNames::PRODUCT_ID],
            'sort' => [['_score' => ['order' => 'desc']]],
        ];
    }

    /** @return int[] product ids in score order */
    public static function extractProductIds(array $response): array
    {
        $ids = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $id = $hit['_source'][FieldNames::PRODUCT_ID] ?? null;
            if ($id === null && isset($hit['_id'])) {
                // "{index}.{productId}.{storeId}"
                $parts = explode('.', (string) $hit['_id']);
                $id = $parts[count($parts) - 2] ?? null;
            }
            if ($id !== null && ctype_digit((string) $id)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    private function prefix(string $field, string $query, float $boost): array
    {
        return ['match_bool_prefix' => [$field => ['query' => $query, 'operator' => 'and', 'boost' => $boost]]];
    }
}
