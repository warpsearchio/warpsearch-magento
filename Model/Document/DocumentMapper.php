<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Document;

/**
 * Pure mapping from an extracted product (plain arrays, see ProductExtractor) to a WarpSearch document.
 * No Magento dependencies, so it is unit-testable on its own.
 */
class DocumentMapper
{
    public function __construct(private readonly FieldNames $names)
    {
    }

    /** Documents of all indexes share a collection, so the id leads with the index name. */
    public static function documentId(string $index, int $productId, int $storeId): string
    {
        return sprintf('%s.%d.%d', strtolower($index), $productId, $storeId);
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:string,1:array<string,mixed>} id and document
     */
    public function map(string $index, array $p): array
    {
        $n = $this->names;
        $doc = [
            FieldNames::INDEX => $index,
            FieldNames::PRODUCT_ID => (string) $p['product_id'],
            FieldNames::STORE => (string) $p['store_id'],
            FieldNames::TYPE => (string) $p['type'],
        ];

        $this->addText($doc, $n->text('name', FieldNames::TEXT_R1), [$p['name'] ?? '']);
        $this->addText($doc, $n->text('sku', FieldNames::TEXT_R1), array_merge([$p['sku'] ?? ''], $p['child_skus'] ?? []));
        $this->addText($doc, $n->text('category', FieldNames::TEXT_R2), $p['category_names'] ?? []);
        $this->addText($doc, $n->text('short_description', FieldNames::TEXT_R2), [$this->plain($p['short_description'] ?? '')]);
        $this->addText($doc, $n->text('description', FieldNames::TEXT_R3), [$this->plain($p['description'] ?? '')]);
        $this->addText($doc, $n->text('child_names', FieldNames::TEXT_R3), $p['child_names'] ?? []);

        $allByRelevance = [
            FieldNames::TEXT_R1 => array_merge([$p['name'] ?? '', $p['sku'] ?? ''], $p['child_skus'] ?? []),
            FieldNames::TEXT_R2 => array_merge($p['category_names'] ?? [], [$this->plain($p['short_description'] ?? '')]),
            FieldNames::TEXT_R3 => array_merge([$this->plain($p['description'] ?? '')], $p['child_names'] ?? []),
            FieldNames::TEXT => [],
        ];

        foreach ($p['attributes'] ?? [] as $attr) {
            $values = array_values(array_filter(array_map('strval', $attr['values']), static fn ($v) => trim($v) !== ''));
            if ($values === []) {
                continue;
            }
            if (!empty($attr['filterable'])) {
                $doc[FieldNames::field($attr['code'], FieldNames::KEYWORDS)] = $values;
            }
            if (!empty($attr['searchable'])) {
                $this->addText($doc, $n->text($attr['code'], FieldNames::TEXT_R2), $values);
                $allByRelevance[FieldNames::TEXT_R2] = array_merge($allByRelevance[FieldNames::TEXT_R2], $values);
            }
        }

        foreach ($allByRelevance as $relevance => $texts) {
            $this->addText($doc, $n->allTexts($relevance), $texts);
        }

        if (($p['name'] ?? '') !== '') {
            $doc[FieldNames::field('name', FieldNames::TEXT_SORT)] = mb_strtolower((string) $p['name']);
        }

        $doc[FieldNames::field('category_id', FieldNames::KEYWORDS)] = array_map('strval', $p['category_ids'] ?? []);
        if ($doc[FieldNames::field('category_id', FieldNames::KEYWORDS)] === []) {
            unset($doc[FieldNames::field('category_id', FieldNames::KEYWORDS)]);
        }

        $doc[FieldNames::field('in_stock', FieldNames::INTEGERS)] = [empty($p['in_stock']) ? 0 : 1];
        $doc[FieldNames::field('visibility', FieldNames::INTEGERS)] = [(int) $p['visibility']];

        foreach (['price_min', 'price_max'] as $key) {
            if (isset($p[$key])) {
                $doc[FieldNames::field($key, FieldNames::DECIMALS)] = [(float) $p[$key]];
                // decimals are also kept as keywords for faceting, as the Umbraco provider does
                $doc[FieldNames::field($key, FieldNames::DECIMAL_KEYWORDS)] = [self::formatDecimal((float) $p[$key])];
            }
        }

        foreach (['created_at', 'updated_at'] as $key) {
            if (!empty($p[$key]) && ($ts = strtotime((string) $p[$key])) !== false) {
                $doc[FieldNames::field($key, FieldNames::DATES)] = [gmdate('Y-m-d\TH:i:s.000\Z', $ts)];
            }
        }

        return [self::documentId($index, (int) $p['product_id'], (int) $p['store_id']), $doc];
    }

    public static function formatDecimal(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html))));
    }

    /** Multiple texts are joined into one value, as the Umbraco provider does. */
    private function addText(array &$doc, string $field, array $texts): void
    {
        $values = array_values(array_filter(array_map('strval', $texts), static fn ($t) => trim($t) !== ''));
        if ($values !== []) {
            $doc[$field] = implode("\n", $values);
        }
    }
}
