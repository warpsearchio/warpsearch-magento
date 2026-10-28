<?php
declare(strict_types=1);

namespace WarpSearch\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use WarpSearch\Search\Model\Api\Client;
use WarpSearch\Search\Model\Config;

class ClientTest extends TestCase
{
    public function testBulkResubmitsOnlyTransientItemFailures(): void
    {
        foreach (['Magento\\Framework\\HTTP\\ClientFactory', 'Magento\\Framework\\HTTP\\ClientInterface'] as $type) {
            if (!class_exists($type) && !interface_exists($type)) {
                $this->markTestSkipped('Needs magento/framework with generated code (run inside a Magento project).');
            }
        }
        // first response: "a" is warming up (HTTP 200, item 503), "b" is a permanent mapping error, "c" is written
        $responses = [
            ['errors' => true, 'items' => [
                ['index' => ['_id' => 'a', 'status' => 503, 'error' => ['type' => 'warming_up', 'reason' => 'retry shortly']]],
                ['index' => ['_id' => 'b', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'bad']]],
                ['delete' => ['_id' => 'c', 'status' => 200]],
            ]],
            ['errors' => false, 'items' => [['index' => ['_id' => 'a', 'status' => 201]]]],
        ];
        $payloads = [];
        $http = $this->createMock('Magento\\Framework\\HTTP\\ClientInterface');
        $http->method('post')->willReturnCallback(function ($url, $body) use (&$payloads) {
            $payloads[] = $body;
        });
        $http->method('getStatus')->willReturn(200);
        $http->method('getBody')->willReturnCallback(function () use (&$responses, &$payloads) {
            return json_encode($responses[count($payloads) - 1]);
        });
        $factory = $this->createMock('Magento\\Framework\\HTTP\\ClientFactory');
        $factory->method('create')->willReturn($http);

        $scope = $this->createMock('Magento\\Framework\\App\\Config\\ScopeConfigInterface');
        $scope->method('getValue')->willReturnMap([
            ['warpsearch/general/collection', 'store', 1, 'magento'],
            ['warpsearch/general/api_url', 'store', null, 'http://localhost'],
            ['warpsearch/general/api_key', 'store', 1, 'key'],
        ]);
        $encryptor = $this->createMock('Magento\\Framework\\Encryption\\EncryptorInterface');
        $encryptor->method('decrypt')->willReturnArgument(0);
        $logger = $this->createMock('Psr\\Log\\LoggerInterface');

        $client = new Client($factory, new Config($scope, $encryptor), $logger);
        $failures = $client->bulk(1, [
            ['id' => 'a', 'doc' => ['f' => 1]],
            ['id' => 'b', 'doc' => ['f' => 2]],
            ['id' => 'c', 'doc' => null],
        ]);

        $this->assertSame([['id' => 'b', 'type' => 'mapper_parsing_exception', 'reason' => 'bad']], $failures);
        $this->assertCount(2, $payloads);
        $this->assertSame('{"index":{"_id":"a"}}' . "\n" . '{"f":1}' . "\n", $payloads[1]);
    }
}
