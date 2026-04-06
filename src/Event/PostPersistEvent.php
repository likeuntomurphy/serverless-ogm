<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Event;

final readonly class PostPersistEvent
{
    public function __construct(
        public object $document,
        public string $table,
    ) {
    }
}
