<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Likeuntomurphy\Serverless\OGM\Metadata\ClassMetadata;
use Likeuntomurphy\Serverless\OGM\Metadata\EmbeddedMetadata;
use Likeuntomurphy\Serverless\OGM\Metadata\MetadataFactory;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Deed;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\FullDeed;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\SurveyLine;

/**
 * @internal
 *
 * @covers \ServerlessOgm\Metadata\MetadataFactory
 */
class MetadataFactoryTest extends TestCase
{
    public function testGetMetadataForCachesResult(): void
    {
        $factory = new MetadataFactory();
        $a = $factory->getMetadataFor(Deed::class);
        $b = $factory->getMetadataFor(Deed::class);

        $this->assertSame($a, $b);
    }

    public function testRegisterMetadata(): void
    {
        $factory = new MetadataFactory();
        $metadata = new ClassMetadata(Deed::class);
        $factory->registerMetadata(Deed::class, $metadata);

        $this->assertSame($metadata, $factory->getMetadataFor(Deed::class));
    }

    public function testRegisterClassAndGetAllMetadata(): void
    {
        $factory = new MetadataFactory();
        $factory->registerClass(Deed::class);
        $factory->registerClass(FullDeed::class);

        $all = $factory->getAllMetadata();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey(Deed::class, $all);
        $this->assertArrayHasKey(FullDeed::class, $all);
        $this->assertInstanceOf(ClassMetadata::class, $all[Deed::class]);
    }

    public function testGetAllMetadataIncludesDirectlyLoadedMetadata(): void
    {
        $factory = new MetadataFactory();
        $factory->getMetadataFor(Deed::class);
        $factory->registerClass(FullDeed::class);

        $all = $factory->getAllMetadata();

        $this->assertCount(2, $all);
    }

    public function testGetEmbeddedMetadataFor(): void
    {
        $factory = new MetadataFactory();
        $meta = $factory->getEmbeddedMetadataFor(SurveyLine::class);

        $this->assertInstanceOf(EmbeddedMetadata::class, $meta);
        $this->assertArrayHasKey('heading', $meta->fields);
        $this->assertArrayHasKey('distance', $meta->fields);
        $this->assertArrayHasKey('course', $meta->fields);
    }

    public function testGetEmbeddedMetadataForCachesResult(): void
    {
        $factory = new MetadataFactory();
        $a = $factory->getEmbeddedMetadataFor(SurveyLine::class);
        $b = $factory->getEmbeddedMetadataFor(SurveyLine::class);

        $this->assertSame($a, $b);
    }
}
