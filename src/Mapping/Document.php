<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
readonly class Document
{
    public function __construct(
        public string $table,
        public ?string $pk = null,
        public ?string $sk = null,
    ) {
    }
}
