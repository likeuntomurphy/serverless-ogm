<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Id
{
    public function __construct(
        public ?string $field = null,
    ) {
    }
}
