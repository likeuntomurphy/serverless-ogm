<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

/**
 * @extends \ArrayAccess<int, object>
 * @extends \IteratorAggregate<int, object>
 */
interface Collection extends \ArrayAccess, \Countable, \IteratorAggregate
{
    public function add(object $entity): void;

    public function remove(object $entity): void;

    /** @return list<object> */
    public function toArray(): array;

    /**
     * @template R
     *
     * @param \Closure(object): R $callback
     *
     * @return list<R>
     */
    public function map(\Closure $callback): array;

    public function isEmpty(): bool;
}
