<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Embedded;
use Likeuntomurphy\Serverless\OGM\Mapping\EmbedOne;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;

#[Embedded]
class Placement
{
    #[Field]
    public string $id = '';

    #[EmbedOne(targetDocument: Coordinate::class)]
    public ?Coordinate $position = null;

    #[Field]
    public bool $active = false;
}
