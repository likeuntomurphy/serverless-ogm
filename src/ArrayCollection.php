<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

class ArrayCollection implements Collection
{
    /** @param list<object> $elements */
    public function __construct(
        private array $elements = [],
    ) {
    }

    public function add(object $entity): void
    {
        $this->elements[] = $entity;
    }

    public function remove(object $entity): void
    {
        foreach ($this->elements as $i => $existing) {
            if ($existing === $entity) {
                unset($this->elements[$i]); // @phpstan-ignore assign.propertyType
                $this->elements = array_values($this->elements);

                return;
            }
        }
    }

    /** @return list<object> */
    public function toArray(): array
    {
        return $this->elements;
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
        return array_map($callback, $this->elements);
    }

    public function isEmpty(): bool
    {
        return [] === $this->elements;
    }

    /** @return \ArrayIterator<int, object> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->elements);
    }

    public function count(): int
    {
        return \count($this->elements);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->elements[(int) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->elements[(int) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->elements[] = $value;
        } else {
            $this->elements[(int) $offset] = $value; // @phpstan-ignore assign.propertyType
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->elements[(int) $offset]); // @phpstan-ignore assign.propertyType
        $this->elements = array_values($this->elements);
    }
}
