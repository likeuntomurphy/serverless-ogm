<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\Identity;
use Likeuntomurphy\Serverless\OGM\IdentityMap;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class IdentityMapTest extends TestCase
{
    public function testGetReturnsNullForUnknown(): void
    {
        $map = new IdentityMap();

        $this->assertNull($map->get('App\Order', new Identity('pk-123')));
    }

    public function testPutAndGetSimpleIdentity(): void
    {
        $map = new IdentityMap();
        $entity = new \stdClass();

        $map->put('App\Order', new Identity('pk-123'), $entity);

        $this->assertSame($entity, $map->get('App\Order', new Identity('pk-123')));
    }

    public function testDistinguishesSamePkDifferentSk(): void
    {
        $map = new IdentityMap();

        $entity1 = new \stdClass();
        $entity2 = new \stdClass();

        $map->put('App\Order', new Identity('user-1', 'order-1'), $entity1);
        $map->put('App\Order', new Identity('user-1', 'order-2'), $entity2);

        $this->assertSame($entity1, $map->get('App\Order', new Identity('user-1', 'order-1')));
        $this->assertSame($entity2, $map->get('App\Order', new Identity('user-1', 'order-2')));
    }

    public function testRemove(): void
    {
        $map = new IdentityMap();

        $entity1 = new \stdClass();
        $entity2 = new \stdClass();

        $map->put('App\Order', new Identity('user-1', 'order-1'), $entity1);
        $map->put('App\Order', new Identity('user-1', 'order-2'), $entity2);

        $map->remove('App\Order', new Identity('user-1', 'order-1'));

        $this->assertNull($map->get('App\Order', new Identity('user-1', 'order-1')));
        $this->assertSame($entity2, $map->get('App\Order', new Identity('user-1', 'order-2')));
    }

    public function testClear(): void
    {
        $map = new IdentityMap();
        $map->put('App\Order', new Identity('pk-1'), new \stdClass());

        $map->clear();

        $this->assertNull($map->get('App\Order', new Identity('pk-1')));
    }
}
