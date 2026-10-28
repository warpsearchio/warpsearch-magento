<?php
declare(strict_types=1);

namespace WarpSearch\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use WarpSearch\Search\Model\Queue\Publisher;

class PublisherTest extends TestCase
{
    public function testDedupesAndChunks(): void
    {
        $iface = 'Magento\\Framework\\MessageQueue\\PublisherInterface';
        if (!interface_exists($iface)) {
            $this->markTestSkipped('Needs magento/framework (composer install in a Magento project).');
        }
        $sent = [];
        $mock = $this->createMock($iface);
        $mock->method('publish')->willReturnCallback(function ($topic, $msg) use (&$sent) {
            $sent[] = [$topic, json_decode($msg, true)['product_ids']];
        });

        $p = new Publisher($mock, 2, false);
        $p->enqueue([1, 2, 2, 0]);
        $p->enqueue([3, 1]);
        $this->assertSame(2, $p->flush());
        $this->assertSame([['warpsearch.product.index', [1, 2]], ['warpsearch.product.index', [3]]], $sent);
        $this->assertSame(0, $p->flush());
    }
}
