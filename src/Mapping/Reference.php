<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Reference
{
    /**
     * @param class-string $targetDocument
     */
    public function __construct(
        public string $targetDocument,
    ) {
    }
}
