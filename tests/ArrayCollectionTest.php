<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ArrayCollectionItem
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
class ArrayCollectionTest extends TestCase
{
    public function testEmpty(): void
    {
        $collection = new ArrayCollection();

        $this->assertTrue($collection->isEmpty());
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testAddAndIterate(): void
    {
        $a = new ArrayCollectionItem('a');
        $b = new ArrayCollectionItem('b');

        $collection = new ArrayCollection();
        $collection->add($a);
        $collection->add($b);

        $this->assertFalse($collection->isEmpty());
        $this->assertCount(2, $collection);
        $this->assertSame([$a, $b], iterator_to_array($collection));
    }

    public function testRemove(): void
    {
        $a = new ArrayCollectionItem('a');
        $b = new ArrayCollectionItem('b');

        $collection = new ArrayCollection([$a, $b]);
        $collection->remove($a);

        $this->assertCount(1, $collection);
        $this->assertSame([$b], $collection->toArray());
    }

    public function testRemoveUnknownIsNoop(): void
    {
        $a = new ArrayCollectionItem('a');
        $other = new ArrayCollectionItem('other');

        $collection = new ArrayCollection([$a]);
        $collection->remove($other);

        $this->assertCount(1, $collection);
    }

    public function testMap(): void
    {
        $collection = new ArrayCollection([
            new ArrayCollectionItem('a'),
            new ArrayCollectionItem('b'),
        ]);

        $ids = $collection->map(fn (object $item): string => $item->id); // @phpstan-ignore property.notFound, return.type

        $this->assertSame(['a', 'b'], $ids);
    }

    public function testArrayAccess(): void
    {
        $a = new ArrayCollectionItem('a');
        $collection = new ArrayCollection([$a]);

        $this->assertTrue(isset($collection[0]));
        $this->assertSame($a, $collection[0]);
        $this->assertFalse(isset($collection[1]));
    }

    public function testOffsetSetAppendsWhenNullOffset(): void
    {
        $collection = new ArrayCollection();
        $collection[] = new ArrayCollectionItem('x');

        $this->assertCount(1, $collection);
    }

    public function testOffsetUnsetReindexes(): void
    {
        $a = new ArrayCollectionItem('a');
        $b = new ArrayCollectionItem('b');
        $collection = new ArrayCollection([$a, $b]);

        unset($collection[0]);

        $this->assertCount(1, $collection);
        $this->assertSame($b, $collection[0]);
    }
}
