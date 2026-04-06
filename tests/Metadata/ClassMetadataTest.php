<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Likeuntomurphy\Serverless\OGM\Metadata\ClassMetadata;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Deed;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\FullDeed;

/**
 * @internal
 *
 * @covers \ServerlessOgm\Metadata\ClassMetadata
 * @covers \ServerlessOgm\Metadata\FieldMapping
 */
class ClassMetadataTest extends TestCase
{
    public function testDeedTable(): void
    {
        $meta = new ClassMetadata(Deed::class);

        $this->assertSame('deeds', $meta->table);
    }

    public function testDeedPartitionKey(): void
    {
        $meta = new ClassMetadata(Deed::class);

        $this->assertNotNull($meta->partitionKey);
        $this->assertSame('PK', $meta->partitionKey->attributeName);
        $this->assertSame('id', $meta->partitionKey->propertyName);
        $this->assertTrue($meta->partitionKey->isPartitionKey);
    }

    public function testDeedFields(): void
    {
        $meta = new ClassMetadata(Deed::class);

        $this->assertArrayHasKey('grantee', $meta->fields);
        $this->assertArrayHasKey('acres', $meta->fields);
        $this->assertArrayHasKey('date', $meta->fields);
    }

    public function testFullDeedReferences(): void
    {
        $meta = new ClassMetadata(FullDeed::class);

        $this->assertArrayHasKey('origin', $meta->fields);
        $this->assertTrue($meta->fields['origin']->isReference());
        $this->assertSame(FullDeed::class, $meta->fields['origin']->referenceTarget);

        $this->assertArrayHasKey('next', $meta->fields);
        $this->assertTrue($meta->fields['next']->isReferenceMany());
        $this->assertSame(FullDeed::class, $meta->fields['next']->referenceTarget);
    }

    public function testFullDeedEmbedMany(): void
    {
        $meta = new ClassMetadata(FullDeed::class);

        $this->assertArrayHasKey('lines', $meta->fields);
        $this->assertTrue($meta->fields['lines']->isEmbedMany());
    }

    public function testFullDeedEnumField(): void
    {
        $meta = new ClassMetadata(FullDeed::class);

        $this->assertArrayHasKey('type', $meta->fields);
    }

    public function testThrowsForNonDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClassMetadata(\stdClass::class);
    }
}
