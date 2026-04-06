<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Embedded;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;

#[Embedded]
class SurveyLine
{
    #[Field]
    public string $heading = '';

    #[Field]
    public string $distance = '0';

    #[Field]
    public ?string $course = null;
}
