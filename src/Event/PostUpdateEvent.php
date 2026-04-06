<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Event;

final readonly class PostUpdateEvent
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changeset
     */
    public function __construct(
        public object $document,
        public string $table,
        public array $changeset,
    ) {
    }
}
