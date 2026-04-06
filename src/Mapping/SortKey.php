<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class SortKey
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
