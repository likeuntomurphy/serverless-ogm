<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class ReferenceMany
{
    /**
     * @param class-string $targetDocument
     */
    public function __construct(
        public string $targetDocument,
        public ?string $adjacencyTable = null,
        public string $adjacencyPk = 'parentId',
        public string $adjacencySk = 'childId',
        public bool $scanForward = true,
    ) {
    }
}
