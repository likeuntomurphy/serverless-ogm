<?php

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\Tests\Fixture\DeedType;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\FullDeed;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\SurveyLine;

/**
 * @internal
 *
 * @covers \ServerlessOgm\Hydrator
 * @covers \ServerlessOgm\Marshaler
 */
class TypeCoercionTest extends DynamoDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTable('full_deeds', 'PK');
    }

    public function testDateTimeRoundTrip(): void
    {
        $deed = new FullDeed();
        $deed->id = 'deed-dt-001';
        $deed->grantee = 'Philip Cox';
        $deed->grantedOn = new \DateTime('1812-03-15');

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-dt-001');
        self::assertNotNull($found);
        $this->assertInstanceOf(\DateTime::class, $found->grantedOn);
        $this->assertSame('1812-03-15', $found->grantedOn->format('Y-m-d'));
    }

    public function testEnumRoundTrip(): void
    {
        $deed = new FullDeed();
        $deed->id = 'deed-enum-001';
        $deed->type = DeedType::BargainAndSale;

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-enum-001');
        self::assertNotNull($found);
        $this->assertSame(DeedType::BargainAndSale, $found->type);
    }

    public function testArrayFieldRoundTrip(): void
    {
        $deed = new FullDeed();
        $deed->id = 'deed-arr-001';
        $deed->grantors = ['Cox, Philip', 'Henry, James'];

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-arr-001');
        self::assertNotNull($found);
        $this->assertSame(['Cox, Philip', 'Henry, James'], $found->grantors);
    }

    public function testEmbedManyRoundTrip(): void
    {
        $line1 = new SurveyLine();
        $line1->heading = 'N45E';
        $line1->distance = '100';
        $line1->course = 'along the ridge';

        $line2 = new SurveyLine();
        $line2->heading = 'S30W';
        $line2->distance = '200';

        $deed = new FullDeed();
        $deed->id = 'deed-embed-001';
        $deed->lines = [$line1, $line2];

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-embed-001');
        self::assertNotNull($found);
        $this->assertCount(2, $found->lines);
        $this->assertInstanceOf(SurveyLine::class, $found->lines[0]);
        $this->assertSame('N45E', $found->lines[0]->heading);
        $this->assertSame('100', $found->lines[0]->distance);
        $this->assertSame('along the ridge', $found->lines[0]->course);
        $this->assertSame('S30W', $found->lines[1]->heading);
        $this->assertNull($found->lines[1]->course);
    }

    public function testSelfReferenceRoundTrip(): void
    {
        $parent = new FullDeed();
        $parent->id = 'deed-parent';
        $parent->grantee = 'Benjamin Borden';
        $parent->acres = 92100.0;

        $this->dm->persist($parent);
        $this->dm->flush();
        $this->dm->clear();

        $child = new FullDeed();
        $child->id = 'deed-child';
        $child->grantee = 'Philip Cox';
        $child->origin = $parent;

        $this->dm->persist($child);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-child');
        self::assertNotNull($found);
        self::assertNotNull($found->origin);

        // ID accessible without initialization
        $this->assertSame('deed-parent', $found->origin->id);

        $ref = new \ReflectionClass($found->origin);
        $this->assertTrue($ref->isUninitializedLazyObject($found->origin));

        // Triggers initialization
        $this->assertSame('Benjamin Borden', $found->origin->grantee);
        $this->assertFalse($ref->isUninitializedLazyObject($found->origin));
    }

    public function testReferenceManyRoundTrip(): void
    {
        $child1 = new FullDeed();
        $child1->id = 'deed-next-1';
        $child1->grantee = 'James Henry';

        $child2 = new FullDeed();
        $child2->id = 'deed-next-2';
        $child2->grantee = 'John Lusk';

        $this->dm->persist($child1);
        $this->dm->persist($child2);
        $this->dm->flush();
        $this->dm->clear();

        $parent = new FullDeed();
        $parent->id = 'deed-with-next';
        $parent->grantee = 'Benjamin Borden';
        $parent->next->add($child1);
        $parent->next->add($child2);

        $this->dm->persist($parent);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-with-next');
        self::assertNotNull($found);
        $this->assertCount(2, $found->next);

        $next = $found->next->toArray();
        \assert($next[0] instanceof FullDeed);
        \assert($next[1] instanceof FullDeed);

        $this->assertSame('deed-next-1', $next[0]->id);
        $this->assertSame('deed-next-2', $next[1]->id);
        $this->assertSame('James Henry', $next[0]->grantee);
        $this->assertSame('John Lusk', $next[1]->grantee);
    }

    public function testFullDeedRoundTrip(): void
    {
        $line = new SurveyLine();
        $line->heading = 'N45E';
        $line->distance = '100';

        $deed = new FullDeed();
        $deed->id = 'deed-full';
        $deed->grantee = 'Philip Cox';
        $deed->acres = 326.0;
        $deed->grantedOn = new \DateTime('1812-03-15');
        $deed->type = DeedType::BargainAndSale;
        $deed->grantors = ['Henry, James'];
        $deed->lines = [$line];

        $this->dm->persist($deed);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(FullDeed::class, 'deed-full');
        self::assertNotNull($found);
        $this->assertSame('Philip Cox', $found->grantee);
        $this->assertSame(326.0, $found->acres);
        self::assertNotNull($found->grantedOn);
        $this->assertSame('1812-03-15', $found->grantedOn->format('Y-m-d'));
        $this->assertSame(DeedType::BargainAndSale, $found->type);
        $this->assertSame(['Henry, James'], $found->grantors);
        $this->assertCount(1, $found->lines);
        $this->assertSame('N45E', $found->lines[0]->heading);
    }
}
