<?php
declare(strict_types=1);

namespace WarpSearch\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use WarpSearch\Search\Model\Document\DocumentMapper;
use WarpSearch\Search\Model\Document\FieldNames;

class DocumentMapperTest extends TestCase
{
    private function product(array $over = []): array
    {
        return $over + [
            'product_id' => 42, 'store_id' => 2, 'type' => 'configurable', 'sku' => 'TEE', 'name' => 'Red Tee',
            'description' => '<p>Soft &amp; warm</p>', 'short_description' => '', 'visibility' => 4, 'in_stock' => true,
            'category_ids' => [3, 5], 'category_names' => ['Tops', 'Sale'], 'child_skus' => ['TEE-S', 'TEE-M'],
            'child_names' => ['Red Tee S'], 'price_min' => 19.5, 'price_max' => 25.0,
            'created_at' => '2024-01-02 03:04:05', 'updated_at' => null,
            'attributes' => [
                ['code' => 'color', 'values' => ['Red'], 'searchable' => true, 'filterable' => true],
                ['code' => 'empty', 'values' => [''], 'searchable' => true, 'filterable' => true],
            ],
        ];
    }

    public function testMapsConfigurableProduct(): void
    {
        [$id, $doc] = (new DocumentMapper(new FieldNames()))->map('Magento', $this->product());

        $this->assertSame('magento.42.2', $id);
        $this->assertSame('magento', strtolower($doc['mg_index_s']));
        $this->assertSame('42', $doc['mg_id_s']);
        $this->assertSame('2', $doc['mg_store_s']);
        $this->assertSame("TEE\nTEE-S\nTEE-M", $doc['f_sku_r1_un']);
        $this->assertSame('Soft & warm', $doc['f_description_r3_un']);
        $this->assertSame(['Red'], $doc['f_color_kw_ss']);
        $this->assertArrayNotHasKey('f_empty_kw_ss', $doc);
        $this->assertSame([19.5], $doc['f_price_min_dc_ds']);
        $this->assertSame(['25'], $doc['f_price_max_dk_ss']);
        $this->assertSame([1], $doc['f_in_stock_in_is']);
        $this->assertSame(['3', '5'], $doc['f_category_id_kw_ss']);
        $this->assertSame(['2024-01-02T03:04:05.000Z'], $doc['f_created_at_dt_dts']);
        $this->assertArrayNotHasKey('f_updated_at_dt_dts', $doc);
        $this->assertStringContainsString('Red Tee', $doc['all_r1_un']);
        $this->assertStringContainsString('Tops', $doc['all_r2_un']);
    }

    public function testOutOfStockAndDecimalFormatting(): void
    {
        [, $doc] = (new DocumentMapper(new FieldNames()))->map('m', $this->product(['in_stock' => false]));
        $this->assertSame([0], $doc['f_in_stock_in_is']);
        $this->assertSame('1.5', DocumentMapper::formatDecimal(1.50));
        $this->assertSame('0', DocumentMapper::formatDecimal(0.0));
    }
}
