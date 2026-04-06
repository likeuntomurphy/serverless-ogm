<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use PHPUnit\Framework\TestCase;
use Likeuntomurphy\Serverless\OGM\Collection;

class CollectionTest extends TestCase
{
    public function testNotInitializedBeforeAccess(): void
    {
        $called = false;
        $collection = new Collection(
            ['id-1', 'id-2'],
            function (array $ids) use (&$called): array {
                $called = true;

                return array_map(fn (string $id) => (object) ['id' => $id], $ids);
            },
        );

        $this->assertFalse($collection->isInitialized());
        $this->assertFalse($called);
    }

    public function testInitializesOnIteration(): void
    {
        $collection = new Collection(
            ['id-1', 'id-2'],
            fn (array $ids): array => array_map(fn (string $id) => (object) ['id' => $id], $ids),
        );

        $items = iterator_to_array($collection);

        $this->assertTrue($collection->isInitialized());
        $this->assertCount(2, $items);
        $this->assertSame('id-1', $items[0]->id);
        $this->assertSame('id-2', $items[1]->id);
    }

    public function testInitializesOnCount(): void
    {
        $collection = new Collection(
            ['a', 'b', 'c'],
            fn (array $ids): array => array_map(fn (string $id) => (object) ['id' => $id], $ids),
        );

        $this->assertCount(3, $collection);
        $this->assertTrue($collection->isInitialized());
    }

    public function testArrayAccess(): void
    {
        $collection = new Collection(
            ['x'],
            fn (array $ids): array => array_map(fn (string $id) => (object) ['id' => $id], $ids),
        );

        $this->assertTrue(isset($collection[0]));
        $this->assertSame('x', $collection[0]->id);
        $this->assertFalse(isset($collection[1]));
    }

    public function testToArray(): void
    {
        $collection = new Collection(
            ['a', 'b'],
            fn (array $ids): array => array_map(fn (string $id) => (object) ['id' => $id], $ids),
        );

        $arr = $collection->toArray();

        $this->assertCount(2, $arr);
        $this->assertSame('a', $arr[0]->id);
    }

    public function testEmptyCollection(): void
    {
        $collection = new Collection(
            [],
            fn (array $ids): array => [],
        );

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testBatchLoaderCalledOnlyOnce(): void
    {
        $callCount = 0;
        $collection = new Collection(
            ['id-1'],
            function (array $ids) use (&$callCount): array {
                ++$callCount;

                return [(object) ['id' => $ids[0]]];
            },
        );

        // Multiple accesses
        \count($collection);
        iterator_to_array($collection);
        $collection[0];
        $collection->toArray();

        $this->assertSame(1, $callCount);
    }
}
