<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Likeuntomurphy\Serverless\OGM\Metadata\EmbeddedMetadata;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Coordinate;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Placement;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\SurveyLine;

/**
 * @internal
 *
 * @covers \ServerlessOgm\Metadata\EmbeddedMetadata
 * @covers \ServerlessOgm\Metadata\FieldMapping
 */
class EmbeddedMetadataTest extends TestCase
{
    public function testParsesFields(): void
    {
        $meta = new EmbeddedMetadata(SurveyLine::class);

        $this->assertCount(3, $meta->fields);
        $this->assertArrayHasKey('heading', $meta->fields);
        $this->assertArrayHasKey('distance', $meta->fields);
        $this->assertArrayHasKey('course', $meta->fields);
    }

    public function testFieldAttributeNames(): void
    {
        $meta = new EmbeddedMetadata(SurveyLine::class);

        $this->assertSame('heading', $meta->fields['heading']->attributeName);
        $this->assertSame('distance', $meta->fields['distance']->attributeName);
    }

    public function testClassName(): void
    {
        $meta = new EmbeddedMetadata(SurveyLine::class);

        $this->assertSame(SurveyLine::class, $meta->className);
    }

    public function testEmbedOneField(): void
    {
        $meta = new EmbeddedMetadata(Placement::class);

        $this->assertArrayHasKey('position', $meta->fields);
        $this->assertSame(Coordinate::class, $meta->fields['position']->embedTarget);
        $this->assertFalse($meta->fields['position']->isEmbedMany());
    }

    public function testPlainFieldsAlongsideEmbed(): void
    {
        $meta = new EmbeddedMetadata(Placement::class);

        $this->assertArrayHasKey('id', $meta->fields);
        $this->assertArrayHasKey('active', $meta->fields);
        $this->assertNull($meta->fields['id']->embedTarget);
    }
}
