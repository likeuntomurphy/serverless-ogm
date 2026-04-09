<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

/**
 * @implements \ArrayAccess<int, object>
 * @implements \IteratorAggregate<int, object>
 */
class Collection implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var list<object> */
    private array $loaded;

    private bool $initialized = false;

    /**
     * @param list<string>                         $ids
     * @param \Closure(list<string>): list<object> $batchLoader
     */
    public function __construct(
        private readonly array $ids,
        private readonly \Closure $batchLoader,
    ) {
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /** @return \ArrayIterator<int, object> */
    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        return new \ArrayIterator($this->loaded);
    }

    public function count(): int
    {
        $this->initialize();

        return \count($this->loaded);
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return isset($this->loaded[(int) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->initialize();

        return $this->loaded[(int) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->initialize();

        if (null === $offset) {
            $this->loaded[] = $value;
        } else {
            $this->loaded[(int) $offset] = $value; // @phpstan-ignore assign.propertyType
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->initialize();
        unset($this->loaded[(int) $offset]); // @phpstan-ignore assign.propertyType
        $this->loaded = array_values($this->loaded);
    }

    /**
     * @template R
     *
     * @param \Closure(object): R $callback
     *
     * @return list<R>
     */
    public function map(\Closure $callback): array
    {
        $this->initialize();

        return array_map($callback, $this->loaded);
    }

    /** @return list<object> */
    public function toArray(): array
    {
        $this->initialize();

        return $this->loaded;
    }

    private function initialize(): void
    {
        if (!$this->initialized) {
            $this->loaded = ($this->batchLoader)($this->ids);
            $this->initialized = true;
        }
    }
}
