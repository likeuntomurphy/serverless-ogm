<?php

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\Identity;
use Aws\DynamoDb\Marshaler;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Deed;

/**
 * @internal
 *
 * @covers \Likeuntomurphy\Serverless\OGM\DocumentManager
 */
class AttachTest extends DynamoDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTable('deeds', 'PK');
    }

    public function testAttachHydratesAndTracksEntity(): void
    {
        $item = ['PK' => 'deed-a', 'grantee' => 'Philip Cox', 'acres' => 326, 'date' => '1812-03-15'];

        $deed = $this->dm->attach(Deed::class, $item);

        $this->assertInstanceOf(Deed::class, $deed);
        $this->assertSame('deed-a', $deed->id);
        $this->assertSame('Philip Cox', $deed->grantee);
        $this->assertSame(326, $deed->acres);
    }

    public function testAttachPutsEntityInIdentityMap(): void
    {
        $item = ['PK' => 'deed-b', 'grantee' => 'James Henry', 'acres' => 259];

        $attached = $this->dm->attach(Deed::class, $item);
        $found = $this->dm->find(Deed::class, new Identity('deed-b'));

        $this->assertSame($attached, $found, 'find() should return the attached instance without hitting DynamoDB');
    }

    public function testAttachingSameIdentityTwiceReturnsSameInstance(): void
    {
        $item = ['PK' => 'deed-c', 'grantee' => 'Philip Cox', 'acres' => 100];

        $first = $this->dm->attach(Deed::class, $item);
        $second = $this->dm->attach(Deed::class, $item);

        $this->assertSame($first, $second);
    }

    public function testAttachDoesNotMakeEntityDirty(): void
    {
        $item = ['PK' => 'deed-d', 'grantee' => 'Philip Cox', 'acres' => 326];
        $this->dm->attach(Deed::class, $item);

        // Flushing without modifying should produce no writes — a dirty-tracking failure
        // would cause a spurious UpdateItem. We verify by mutating after flush and checking
        // the entity round-trips as an update, not as a fresh persist.
        $this->dm->flush();
        $this->dm->clear();

        $reloaded = $this->dm->find(Deed::class, new Identity('deed-d'));
        $this->assertNull($reloaded, 'attach() must not persist — entity should not exist in DynamoDB');
    }

    public function testAttachedEntityFlushesAsUpdateAfterMutation(): void
    {
        // Seed DynamoDB via persist/flush so the row exists
        $seed = new Deed();
        $seed->id = 'deed-e';
        $seed->grantee = 'Philip Cox';
        $seed->acres = 326;
        $this->dm->persist($seed);
        $this->dm->flush();
        $this->dm->clear();

        // Now simulate an app-owned Query: fetch raw item, unmarshal, attach
        $marshaler = new Marshaler(['nullify_invalid' => true]);
        $result = $this->dm->getClient()->getItem([
            'TableName' => $this->dm->tableName('deeds'),
            'Key' => $marshaler->marshalItem(['PK' => 'deed-e']),
        ]);

        /** @var array<string, mixed> $rawItem */
        $rawItem = $marshaler->unmarshalItem((array) $result['Item']);

        $attached = $this->dm->attach(Deed::class, $rawItem);
        $attached->acres = 400;
        $this->dm->flush();
        $this->dm->clear();

        $reloaded = $this->dm->find(Deed::class, new Identity('deed-e'));
        $this->assertNotNull($reloaded);
        $this->assertSame(400, $reloaded->acres);
        $this->assertSame('Philip Cox', $reloaded->grantee, 'untouched fields must survive the update');
    }

    public function testAttachAllReturnsListInOrderAndDedupes(): void
    {
        $items = [
            ['PK' => 'deed-f1', 'grantee' => 'A', 'acres' => 1],
            ['PK' => 'deed-f2', 'grantee' => 'B', 'acres' => 2],
            ['PK' => 'deed-f1', 'grantee' => 'A', 'acres' => 1], // duplicate
        ];

        $deeds = $this->dm->attachAll(Deed::class, $items);

        $this->assertCount(3, $deeds);
        $this->assertSame('deed-f1', $deeds[0]->id);
        $this->assertSame('deed-f2', $deeds[1]->id);
        $this->assertSame($deeds[0], $deeds[2], 'duplicate identity should return the same instance');
    }

    public function testAttachThrowsWhenPartitionKeyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing or non-scalar partition key');

        $this->dm->attach(Deed::class, ['grantee' => 'no-pk-here']);
    }
}
