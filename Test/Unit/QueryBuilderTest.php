<?php
declare(strict_types=1);

namespace WarpSearch\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use WarpSearch\Search\Model\Api\QueryBuilder;
use WarpSearch\Search\Model\Document\FieldNames;

class QueryBuilderTest extends TestCase
{
    public function testBuildsFilteredPrefixQuery(): void
    {
        $body = (new QueryBuilder(new FieldNames()))->build('magento', 3, 'red te', 0, 6, true);

        $filter = $body['query']['bool']['filter'];
        $this->assertSame(['mg_index_s' => ['magento']], $filter[0]['terms']);
        $this->assertSame(['mg_store_s' => ['3']], $filter[1]['terms']);
        $this->assertSame(['f_in_stock_in_is' => [1]], $filter[2]['terms']);

        $should = $body['query']['bool']['must'][0]['bool']['should'];
        $this->assertCount(4, $should);
        $this->assertSame('and', $should[1]['match_bool_prefix']['all_r1_un']['operator']);
        $this->assertSame('red te', $should[1]['match_bool_prefix']['all_r1_un']['query']);
        $this->assertSame(6, $body['size']);
        $this->assertSame(['mg_id_s'], $body['_source']);
    }

    public function testExtractsIdsFromSourceOrDocumentId(): void
    {
        $ids = QueryBuilder::extractProductIds(['hits' => ['hits' => [
            ['_id' => 'magento.7.1', '_source' => ['mg_id_s' => '7']],
            ['_id' => 'magento.9.1'],
            ['_id' => 'garbage', '_source' => []],
        ]]]);
        $this->assertSame([7, 9], $ids);
    }
}
