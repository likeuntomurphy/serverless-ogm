<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\PersistentCollection;
use PHPUnit\Framework\TestCase;

final class PersistentCollectionItem
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
class PersistentCollectionTest extends TestCase
{
    public function testCountDoesNotHydrate(): void
    {
        $queryCalled = false;
        $collection = new PersistentCollection(
            queryExecutor: function (int $limit, ?array $cursor) use (&$queryCalled): array {
                $queryCalled = true;

                return ['items' => [], 'childIds' => [], 'lastEvaluatedKey' => null];
            },
            countExecutor: fn (): int => 42,
        );

        $this->assertCount(42, $collection);
        $this->assertFalse($collection->isInitialized());
        $this->assertFalse($queryCalled);
    }

    public function testIsEmptyUsesCountWhenUninitialized(): void
    {
        $countCalls = 0;
        $collection = new PersistentCollection(
            queryExecutor: fn (int $limit, ?array $cursor): array => ['items' => [], 'childIds' => [], 'lastEvaluatedKey' => null],
            countExecutor: function () use (&$countCalls): int {
                ++$countCalls;

                return 0;
            },
        );

        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->isInitialized());
        $this->assertSame(1, $countCalls);
    }

    public function testChildIdsUsesIdsExecutorWithoutHydrating(): void
    {
        $queryCalled = false;
        $collection = new PersistentCollection(
            queryExecutor: function (int $limit, ?array $cursor) use (&$queryCalled): array {
                $queryCalled = true;

                return ['items' => [], 'childIds' => [], 'lastEvaluatedKey' => null];
            },
            countExecutor: fn (): int => 3,
            idsExecutor: fn (int $limit, ?array $cursor): array => ['childIds' => ['a', 'b', 'c'], 'lastEvaluatedKey' => null],
        );

        $this->assertSame(['a', 'b', 'c'], $collection->childIds());
        $this->assertFalse($collection->isInitialized());
        $this->assertFalse($queryCalled);
    }

    public function testChildIdsPagesThroughLastEvaluatedKey(): void
    {
        $pages = [
            ['childIds' => ['a', 'b'], 'lastEvaluatedKey' => ['k' => 'b']],
            ['childIds' => ['c'], 'lastEvaluatedKey' => null],
        ];
        $call = 0;
        $collection = new PersistentCollection(
            queryExecutor: fn (int $limit, ?array $cursor): array => ['items' => [], 'childIds' => [], 'lastEvaluatedKey' => null],
            countExecutor: fn (): int => 3,
            idsExecutor: function (int $limit, ?array $cursor) use (&$pages, &$call): array { // @phpstan-ignore argument.type
                return $pages[$call++];
            },
        );

        $this->assertSame(['a', 'b', 'c'], $collection->childIds());
        $this->assertSame(2, $call);
    }

    public function testAddTracksMutation(): void
    {
        $collection = $this->emptyCollection();
        $item = new PersistentCollectionItem('x');

        $collection->add($item);

        $this->assertCount(1, $collection->getAdded());
        $this->assertTrue($collection->getAdded()->contains($item));
        $this->assertCount(0, $collection->getRemoved());
    }

    public function testRemoveOfPendingAddCancels(): void
    {
        $collection = $this->emptyCollection();
        $item = new PersistentCollectionItem('x');

        $collection->add($item);
        $collection->remove($item);

        $this->assertCount(0, $collection->getAdded());
        $this->assertCount(0, $collection->getRemoved());
    }

    public function testAddOfPendingRemoveCancels(): void
    {
        $collection = $this->prefilledCollection(['a']);
        $collection->toArray(); // force initialize
        $loaded = $collection->toArray()[0];

        $collection->remove($loaded);
        $this->assertCount(1, $collection->getRemoved());

        $collection->add($loaded);
        $this->assertCount(0, $collection->getRemoved());
        $this->assertCount(0, $collection->getAdded());
    }

    public function testClearMutationsResetsTrackingOnly(): void
    {
        $collection = $this->emptyCollection();
        $collection->add(new PersistentCollectionItem('x'));
        $collection->add(new PersistentCollectionItem('y'));

        $this->assertCount(2, $collection->getAdded());
        $collection->clearMutations();
        $this->assertCount(0, $collection->getAdded());
    }

    public function testInitializePagesThroughLastEvaluatedKey(): void
    {
        $pages = [
            [
                'items' => [new PersistentCollectionItem('a'), new PersistentCollectionItem('b')],
                'childIds' => ['a', 'b'],
                'lastEvaluatedKey' => ['k' => 'b'],
            ],
            [
                'items' => [new PersistentCollectionItem('c')],
                'childIds' => ['c'],
                'lastEvaluatedKey' => null,
            ],
        ];
        $call = 0;
        $collection = new PersistentCollection(
            queryExecutor: function (int $limit, ?array $cursor) use (&$pages, &$call): array { // @phpstan-ignore argument.type
                return $pages[$call++];
            },
            countExecutor: fn (): int => 3,
        );

        $this->assertCount(3, $collection->toArray());
        $this->assertSame(2, $call);
        $this->assertTrue($collection->isInitialized());
    }

    private function emptyCollection(): PersistentCollection
    {
        return new PersistentCollection(
            queryExecutor: fn (int $limit, ?array $cursor): array => ['items' => [], 'childIds' => [], 'lastEvaluatedKey' => null],
            countExecutor: fn (): int => 0,
        );
    }

    /**
     * @param list<string> $ids
     */
    private function prefilledCollection(array $ids): PersistentCollection
    {
        $items = array_map(fn (string $id) => new PersistentCollectionItem($id), $ids);

        return new PersistentCollection(
            queryExecutor: fn (int $limit, ?array $cursor): array => ['items' => $items, 'childIds' => $ids, 'lastEvaluatedKey' => null],
            countExecutor: fn (): int => \count($ids),
        );
    }
}
