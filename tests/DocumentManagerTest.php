<?php

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\Identity;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Deed;

/**
 * @internal
 *
 * @covers \ServerlessOgm\DocumentManager
 * @covers \ServerlessOgm\Hydrator
 * @covers \ServerlessOgm\IdentityMap
 * @covers \ServerlessOgm\Marshaler
 * @covers \ServerlessOgm\UnitOfWork
 */
class DocumentManagerTest extends DynamoDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTable('deeds', 'PK');
    }

    public function testPersistAndFind(): void
    {
        $deed = new Deed();
        $deed->id = 'deed-001';
        $deed->grantee = 'Philip Cox';
        $deed->acres = 326;
        $deed->date = '1812-03-15';

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(Deed::class, new Identity('deed-001'));

        $this->assertNotNull($found);
        $this->assertSame('deed-001', $found->id);
        $this->assertSame('Philip Cox', $found->grantee);
        $this->assertSame(326, $found->acres);
        $this->assertSame('1812-03-15', $found->date);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->dm->find(Deed::class, new Identity('nonexistent')));
    }

    public function testIdentityMapReturnsSameInstance(): void
    {
        $deed = new Deed();
        $deed->id = 'deed-002';
        $deed->grantee = 'James Henry';
        $deed->acres = 259;

        $this->dm->persist($deed);
        $this->dm->flush();

        $a = $this->dm->find(Deed::class, new Identity('deed-002'));
        $b = $this->dm->find(Deed::class, new Identity('deed-002'));

        $this->assertSame($a, $b);
    }

    public function testUpdateAndFlush(): void
    {
        $deed = new Deed();
        $deed->id = 'deed-003';
        $deed->grantee = 'Philip Cox';
        $deed->acres = 326;

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(Deed::class, new Identity('deed-003'));
        self::assertNotNull($found);
        $found->acres = 330;
        $this->dm->flush();
        $this->dm->clear();

        $updated = $this->dm->find(Deed::class, new Identity('deed-003'));
        self::assertNotNull($updated);
        $this->assertSame(330, $updated->acres);
    }
}
