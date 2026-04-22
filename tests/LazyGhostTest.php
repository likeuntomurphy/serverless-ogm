<?php

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Grant;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Person;

/**
 * @internal
 *
 * @covers \ServerlessOgm\DocumentManager
 * @covers \ServerlessOgm\Hydrator
 */
class LazyGhostTest extends DynamoDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTable('persons', 'PK');
        $this->ensureTable('grants', 'PK');
    }

    public function testIdAccessibleWithoutInitialization(): void
    {
        $person = new Person();
        $person->id = 'person-001';
        $person->name = 'Philip Cox';
        $this->dm->persist($person);
        $this->dm->flush();
        $this->dm->clear();

        $grant = new Grant();
        $grant->id = 'grant-001';
        $grant->acres = 326;
        $grant->grantee = $person;
        $this->dm->persist($grant);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(Grant::class, 'grant-001');
        self::assertNotNull($found);
        self::assertNotNull($found->grantee);

        $ref = new \ReflectionClass($found->grantee);

        // ID is accessible without triggering initialization
        $this->assertSame('person-001', $found->grantee->id);
        $this->assertTrue($ref->isUninitializedLazyObject($found->grantee));
    }

    public function testAccessingNonIdPropertyTriggersInitialization(): void
    {
        $person = new Person();
        $person->id = 'person-002';
        $person->name = 'James Henry';
        $this->dm->persist($person);
        $this->dm->flush();
        $this->dm->clear();

        $grant = new Grant();
        $grant->id = 'grant-002';
        $grant->acres = 259;
        $grant->grantee = $person;
        $this->dm->persist($grant);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(Grant::class, 'grant-002');
        self::assertNotNull($found);
        self::assertNotNull($found->grantee);

        $ref = new \ReflectionClass($found->grantee);

        $this->assertTrue($ref->isUninitializedLazyObject($found->grantee));

        // Accessing name triggers initialization
        $this->assertSame('James Henry', $found->grantee->name);
        $this->assertFalse($ref->isUninitializedLazyObject($found->grantee));
        $this->assertSame('person-002', $found->grantee->id);
    }

    public function testReferenceToMissingDocumentThrows(): void
    {
        $this->client->putItem([
            'TableName' => 'grants'.$this->tableSuffix,
            'Item' => [
                'PK' => ['S' => 'grant-bad'],
                'acres' => ['N' => '100'],
                'grantee' => ['S' => 'nonexistent'],
            ],
        ]);

        $found = $this->dm->find(Grant::class, 'grant-bad');
        self::assertNotNull($found);
        self::assertNotNull($found->grantee);

        // ID is still accessible
        $this->assertSame('nonexistent', $found->grantee->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found.');

        // Accessing non-ID property triggers the exception
        $_ = $found->grantee->name;
    }
}
