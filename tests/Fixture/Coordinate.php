<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Embedded;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;

#[Embedded]
class Coordinate
{
    #[Field]
    public float $x = 0.0;

    #[Field]
    public float $y = 0.0;
}
