<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\FlushStrategy;

readonly class FlushResult
{
    /**
     * @param list<int> $succeededWriteIndices
     * @param list<int> $succeededDeleteIndices
     * @param list<int> $failedWriteIndices
     * @param list<int> $failedDeleteIndices
     */
    public function __construct(
        public array $succeededWriteIndices,
        public array $succeededDeleteIndices,
        public array $failedWriteIndices = [],
        public array $failedDeleteIndices = [],
    ) {
    }

    public function hasFailures(): bool
    {
        return [] !== $this->failedWriteIndices || [] !== $this->failedDeleteIndices;
    }
}
