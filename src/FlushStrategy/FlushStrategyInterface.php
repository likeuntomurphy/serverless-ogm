<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\FlushStrategy;

interface FlushStrategyInterface
{
    /**
     * @param list<array{table: string, item: array<string, mixed>, key: array<string, mixed>, isNew: bool, fieldChanges: array<string, array{old: mixed, new: mixed}>}> $writes
     * @param list<array{table: string, key: array<string, mixed>}>                                                                                                      $deletes
     */
    public function execute(array $writes, array $deletes): FlushResult;
}
