<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Field
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
