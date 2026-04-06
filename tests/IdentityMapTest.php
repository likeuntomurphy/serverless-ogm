<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use PHPUnit\Framework\TestCase;
use Likeuntomurphy\Serverless\OGM\IdentityMap;

class IdentityMapTest extends TestCase
{
    public function testCompositeKeyWithoutSortKey(): void
    {
        $this->assertSame('pk-123', IdentityMap::compositeKey('pk-123'));
        $this->assertSame('pk-123', IdentityMap::compositeKey('pk-123', null));
    }

    public function testCompositeKeyWithSortKey(): void
    {
        $key = IdentityMap::compositeKey('pk-123', 'sk-456');
        $this->assertSame("pk-123\0sk-456", $key);
    }

    public function testCompositeKeyDistinguishesSamePkDifferentSk(): void
    {
        $map = new IdentityMap();

        $entity1 = new \stdClass();
        $entity2 = new \stdClass();

        $key1 = IdentityMap::compositeKey('user-1', 'order-1');
        $key2 = IdentityMap::compositeKey('user-1', 'order-2');

        $map->put('App\Order', $key1, $entity1);
        $map->put('App\Order', $key2, $entity2);

        $this->assertSame($entity1, $map->get('App\Order', $key1));
        $this->assertSame($entity2, $map->get('App\Order', $key2));
        $this->assertNotSame($entity1, $entity2);
    }

    public function testRemoveWithCompositeKey(): void
    {
        $map = new IdentityMap();

        $entity1 = new \stdClass();
        $entity2 = new \stdClass();

        $key1 = IdentityMap::compositeKey('user-1', 'order-1');
        $key2 = IdentityMap::compositeKey('user-1', 'order-2');

        $map->put('App\Order', $key1, $entity1);
        $map->put('App\Order', $key2, $entity2);

        $map->remove('App\Order', $key1);

        $this->assertNull($map->get('App\Order', $key1));
        $this->assertSame($entity2, $map->get('App\Order', $key2));
    }
}
