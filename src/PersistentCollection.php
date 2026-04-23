<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

class PersistentCollection implements Collection
{
    /** @var list<object> */
    private array $loaded;

    private bool $initialized = false;

    /** @var \SplObjectStorage<object, true> */
    private \SplObjectStorage $added;

    /** @var \SplObjectStorage<object, true> */
    private \SplObjectStorage $removed;

    /**
     * @param \Closure(int, ?array<string, mixed>): array{items: list<object>, childIds: list<string>, lastEvaluatedKey: ?array<string, mixed>} $queryExecutor
     * @param \Closure(): int                                                                                                                   $countExecutor
     * @param ?\Closure(int, ?array<string, mixed>): array{childIds: list<string>, lastEvaluatedKey: ?array<string, mixed>}                     $idsExecutor
     */
    public function __construct(
        private readonly \Closure $queryExecutor,
        private readonly \Closure $countExecutor,
        private readonly ?\Closure $idsExecutor = null,
    ) {
        $this->added = new \SplObjectStorage();
        $this->removed = new \SplObjectStorage();
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    // --- Mutation tracking ---

    public function add(object $entity): void
    {
        if ($this->removed->offsetExists($entity)) {
            $this->removed->offsetUnset($entity);

            return;
        }

        $this->added->offsetSet($entity, true);

        if ($this->initialized) {
            $this->loaded[] = $entity;
        }
    }

    public function remove(object $entity): void
    {
        if ($this->added->offsetExists($entity)) {
            $this->added->offsetUnset($entity);

            if ($this->initialized) {
                foreach ($this->loaded as $i => $existing) {
                    if ($existing === $entity) {
                        unset($this->loaded[$i]); // @phpstan-ignore assign.propertyType
                        $this->loaded = array_values($this->loaded);

                        break;
                    }
                }
            }

            return;
        }

        $this->removed->offsetSet($entity, true);

        if ($this->initialized) {
            foreach ($this->loaded as $i => $existing) {
                if ($existing === $entity) {
                    unset($this->loaded[$i]); // @phpstan-ignore assign.propertyType
                    $this->loaded = array_values($this->loaded);

                    break;
                }
            }
        }
    }

    /**
     * Replace the collection contents with the given entities.
     *
     * @param list<object> $entities
     */
    public function sync(array $entities): void
    {
        $this->initialize();

        $newSet = new \SplObjectStorage();
        foreach ($entities as $entity) {
            $newSet->offsetSet($entity, true);
        }

        foreach ($this->loaded as $existing) {
            if (!$newSet->offsetExists($existing)) {
                $this->remove($existing);
            }
        }

        foreach ($entities as $entity) {
            if (!$this->contains($entity)) {
                $this->add($entity);
            }
        }

        $this->loaded = $entities;
    }

    /** @return \SplObjectStorage<object, true> */
    public function getAdded(): \SplObjectStorage
    {
        return $this->added;
    }

    /** @return \SplObjectStorage<object, true> */
    public function getRemoved(): \SplObjectStorage
    {
        return $this->removed;
    }

    public function clearMutations(): void
    {
        $this->added = new \SplObjectStorage();
        $this->removed = new \SplObjectStorage();
    }

    // --- Collection interface ---

    /** @return list<object> */
    public function toArray(): array
    {
        $this->initialize();

        return $this->loaded;
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

    public function isEmpty(): bool
    {
        if ($this->initialized) {
            return [] === $this->loaded;
        }

        return 0 === ($this->countExecutor)();
    }

    /** @return \ArrayIterator<int, object> */
    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        return new \ArrayIterator($this->loaded);
    }

    public function count(): int
    {
        if (!$this->initialized) {
            return max(0, ($this->countExecutor)());
        }

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
        $this->add($value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->initialize();

        $entity = $this->loaded[(int) $offset] ?? null;
        if (null !== $entity) {
            $this->remove($entity);
        }
    }

    /**
     * Return child IDs without hydrating entities.
     * Queries the adjacency table directly — much cheaper than initializing.
     *
     * @return list<string>
     */
    public function childIds(): array
    {
        if (null === $this->idsExecutor) {
            return [];
        }

        $allIds = [];
        $exclusiveStartKey = null;

        do {
            $result = ($this->idsExecutor)(\PHP_INT_MAX, $exclusiveStartKey);
            $allIds = array_merge($allIds, $result['childIds']);
            $exclusiveStartKey = $result['lastEvaluatedKey'];
        } while (null !== $exclusiveStartKey);

        return $allIds;
    }

    /**
     * Execute a paginated query. Returns raw results for the caller to wrap
     * in presentation-layer types (e.g., GraphQL connections).
     *
     * @param ?array<string, mixed> $exclusiveStartKey
     *
     * @return array{items: list<object>, childIds: list<string>, lastEvaluatedKey: ?array<string, mixed>}
     */
    public function slice(int $limit, ?array $exclusiveStartKey = null): array
    {
        return ($this->queryExecutor)($limit, $exclusiveStartKey);
    }

    // --- Internal ---

    private function contains(object $entity): bool
    {
        foreach ($this->loaded as $existing) {
            if ($existing === $entity) {
                return true;
            }
        }

        return false;
    }

    private function initialize(): void
    {
        if (!$this->initialized) {
            $allItems = [];
            $exclusiveStartKey = null;

            do {
                $result = ($this->queryExecutor)(\PHP_INT_MAX, $exclusiveStartKey);
                $allItems = array_merge($allItems, $result['items']);
                $exclusiveStartKey = $result['lastEvaluatedKey'];
            } while (null !== $exclusiveStartKey);

            $this->loaded = $allItems;
            $this->initialized = true;
        }
    }
}
