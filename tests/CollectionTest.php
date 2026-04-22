<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Minimal typed fixture for Collection tests — avoids stdClass casts
 * that PHPStan max can't narrow for property access.
 */
final class CollectionTestItem
{
    public function __construct(
        public string $id,
    ) {
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
class CollectionTest extends TestCase
{
    public function testNotInitializedBeforeAccess(): void
    {
        $called = false;
        $collection = new Collection(
            ['id-1', 'id-2'],
            function (array $ids) use (&$called): array {
                $called = true;

                return array_map(fn (string $id) => new CollectionTestItem($id), $ids);
            },
        );

        $this->assertFalse($collection->isInitialized());
        $this->assertFalse($called);
    }

    public function testInitializesOnIteration(): void
    {
        $collection = new Collection(
            ['id-1', 'id-2'],
            fn (array $ids): array => array_map(fn (string $id) => new CollectionTestItem($id), $ids),
        );

        $items = iterator_to_array($collection);

        $this->assertTrue($collection->isInitialized());
        $this->assertCount(2, $items);
        $this->assertInstanceOf(CollectionTestItem::class, $items[0]);
        $this->assertInstanceOf(CollectionTestItem::class, $items[1]);
        $this->assertSame('id-1', $items[0]->id);
        $this->assertSame('id-2', $items[1]->id);
    }

    public function testInitializesOnCount(): void
    {
        $collection = new Collection(
            ['a', 'b', 'c'],
            fn (array $ids): array => array_map(fn (string $id) => new CollectionTestItem($id), $ids),
        );

        $this->assertCount(3, $collection);
        $this->assertTrue($collection->isInitialized());
    }

    public function testArrayAccess(): void
    {
        $collection = new Collection(
            ['x'],
            fn (array $ids): array => array_map(fn (string $id) => new CollectionTestItem($id), $ids),
        );

        $this->assertTrue(isset($collection[0]));
        $first = $collection[0];
        $this->assertInstanceOf(CollectionTestItem::class, $first);
        $this->assertSame('x', $first->id);
        $this->assertFalse(isset($collection[1]));
    }

    public function testToArray(): void
    {
        $collection = new Collection(
            ['a', 'b'],
            fn (array $ids): array => array_map(fn (string $id) => new CollectionTestItem($id), $ids),
        );

        $arr = $collection->toArray();

        $this->assertCount(2, $arr);
        $this->assertInstanceOf(CollectionTestItem::class, $arr[0]);
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

                return [new CollectionTestItem($ids[0])];
            },
        );

        // Multiple accesses — all should reuse the initial load
        $this->assertCount(1, $collection);
        $this->assertCount(1, iterator_to_array($collection));
        $this->assertNotNull($collection[0]);
        $this->assertCount(1, $collection->toArray());

        $this->assertSame(1, $callCount);
    }
}
