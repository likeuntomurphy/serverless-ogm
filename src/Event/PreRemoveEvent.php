<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Event;

final readonly class PreRemoveEvent
{
    public function __construct(
        public object $document,
        public string $table,
    ) {
    }
}
