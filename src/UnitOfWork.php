<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

use Likeuntomurphy\Serverless\OGM\FlushStrategy\FlushResult;
use Likeuntomurphy\Serverless\OGM\Metadata\MetadataFactory;

class UnitOfWork
{
    private const string STATE_NEW = 'new';
    private const string STATE_MANAGED = 'managed';
    private const string STATE_REMOVED = 'removed';

    /** @var \SplObjectStorage<object, array{state: string, snapshot: array<string, mixed>}> */
    private \SplObjectStorage $entities;

    public function __construct(
        private readonly MetadataFactory $metadataFactory,
        private readonly Hydrator $hydrator,
    ) {
        $this->entities = new \SplObjectStorage();
    }

    public function persist(object $entity): void
    {
        if ($this->entities->offsetExists($entity)) {
            return;
        }

        $this->entities->offsetSet($entity, ['state' => self::STATE_NEW, 'snapshot' => []]);
    }

    public function remove(object $entity): void
    {
        if (!$this->entities->offsetExists($entity)) {
            throw new \LogicException('Cannot remove an entity that is not managed.');
        }

        $data = $this->entities[$entity];
        $this->entities->offsetSet($entity, ['state' => self::STATE_REMOVED, 'snapshot' => $data['snapshot']]);
    }

    /**
     * @return list<array{entity: object, table: string, key: array<string, mixed>}>
     */
    public function getRemovals(): array
    {
        $removals = [];

        foreach ($this->entities as $entity) {
            $data = $this->entities[$entity];
            if (self::STATE_REMOVED !== $data['state']) {
                continue;
            }

            $metadata = $this->metadataFactory->getMetadataFor($entity::class);
            $idMapping = $metadata->partitionKey ?? $metadata->idField;
            if (!$idMapping) {
                throw new \LogicException(sprintf('No key field defined on "%s".', $entity::class));
            }

            $id = $metadata->reflectionProperties[$idMapping->propertyName]->getValue($entity);

            $key = [$idMapping->attributeName => $id];
            if ($metadata->sortKey) {
                $key[$metadata->sortKey->attributeName] = $metadata->reflectionProperties[$metadata->sortKey->propertyName]->getValue($entity);
            }

            $removals[] = [
                'entity' => $entity,
                'table' => $metadata->table,
                'key' => $key,
            ];
        }

        return $removals;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function registerManaged(object $entity, array $snapshot): void
    {
        $this->entities->offsetSet($entity, ['state' => self::STATE_MANAGED, 'snapshot' => $snapshot]);
    }

    /**
     * @return list<array{entity: object, item: array<string, mixed>, table: string, isNew: bool, fieldChanges: array<string, array{old: mixed, new: mixed}>}>
     */
    public function getChangeset(): array
    {
        $changeset = [];

        foreach ($this->entities as $entity) {
            $data = $this->entities[$entity];
            $metadata = $this->metadataFactory->getMetadataFor($entity::class);
            $currentItem = $this->hydrator->extract($metadata, $entity);
            $isNew = self::STATE_NEW === $data['state'];

            if (self::STATE_REMOVED === $data['state']) {
                continue;
            }

            if ($isNew || $currentItem !== $data['snapshot']) {
                $fieldChanges = [];
                if (!$isNew) {
                    foreach ($currentItem as $key => $value) {
                        $old = $data['snapshot'][$key] ?? null;
                        if ($value !== $old) {
                            $fieldChanges[$key] = ['old' => $old, 'new' => $value];
                        }
                    }
                    foreach ($data['snapshot'] as $key => $old) {
                        if (!\array_key_exists($key, $currentItem)) {
                            $fieldChanges[$key] = ['old' => $old, 'new' => null];
                        }
                    }
                }

                $changeset[] = [
                    'entity' => $entity,
                    'item' => $currentItem,
                    'table' => $metadata->table,
                    'isNew' => $isNew,
                    'fieldChanges' => $fieldChanges,
                ];
            }
        }

        return $changeset;
    }

    /**
     * @param list<array{entity: object, item: array<string, mixed>, table: string, isNew: bool, fieldChanges: array<string, array{old: mixed, new: mixed}>}> $changeset
     * @param list<array{entity: object, table: string, key: array<string, mixed>}>                                                                           $removals
     */
    public function postFlush(FlushResult $result, array $changeset, array $removals): void
    {
        // Build sets of succeeded entities for fast lookup
        $succeededWriteEntities = new \SplObjectStorage();
        foreach ($result->succeededWriteIndices as $i) {
            if (isset($changeset[$i])) {
                $succeededWriteEntities->attach($changeset[$i]['entity']);
            }
        }

        $succeededDeleteEntities = new \SplObjectStorage();
        foreach ($result->succeededDeleteIndices as $i) {
            if (isset($removals[$i])) {
                $succeededDeleteEntities->attach($removals[$i]['entity']);
            }
        }

        $toDetach = [];

        foreach ($this->entities as $entity) {
            $data = $this->entities[$entity];

            if (self::STATE_REMOVED === $data['state']) {
                if ($succeededDeleteEntities->contains($entity)) {
                    $toDetach[] = $entity;
                }

                continue;
            }

            // Only re-snapshot entities whose writes succeeded
            if ($succeededWriteEntities->contains($entity)) {
                $metadata = $this->metadataFactory->getMetadataFor($entity::class);
                $snapshot = $this->hydrator->extract($metadata, $entity);
                $this->entities->offsetSet($entity, ['state' => self::STATE_MANAGED, 'snapshot' => $snapshot]);
            }
        }

        foreach ($toDetach as $entity) {
            $this->entities->offsetUnset($entity);
        }
    }

    public function clear(): void
    {
        $this->entities = new \SplObjectStorage();
    }

    /**
     * Collect pending adjacency-table mutations from managed entities.
     * Each entry corresponds to one PersistentCollection with mutations;
     * `writes` / `deletes` use the same shape as the main flush pipeline.
     *
     * @return list<array{
     *     collection: PersistentCollection,
     *     writes: list<array{table: string, item: array<string, mixed>, key: array<string, mixed>, isNew: bool, fieldChanges: array<string, array{old: mixed, new: mixed}>}>,
     *     deletes: list<array{table: string, key: array<string, mixed>}>,
     * }>
     */
    public function getAdjacencyMutations(): array
    {
        $mutations = [];

        foreach ($this->entities as $entity) {
            $data = $this->entities[$entity];
            if (self::STATE_REMOVED === $data['state']) {
                continue;
            }

            $metadata = $this->metadataFactory->getMetadataFor($entity::class);
            $pkMapping = $metadata->partitionKey ?? $metadata->idField;
            if (null === $pkMapping) {
                continue;
            }

            $parentId = (string) $metadata->reflectionProperties[$pkMapping->propertyName]->getValue($entity); // @phpstan-ignore cast.string

            foreach ($metadata->fields as $fieldMapping) {
                if (!$fieldMapping->isReferenceMany() || null === $fieldMapping->adjacencyTable) {
                    continue;
                }

                $property = $metadata->reflectionProperties[$fieldMapping->propertyName];
                if (!$property->isInitialized($entity)) {
                    continue;
                }

                $collection = $property->getValue($entity);
                if (!$collection instanceof PersistentCollection) {
                    continue;
                }

                $added = $collection->getAdded();
                $removed = $collection->getRemoved();
                if (0 === $added->count() && 0 === $removed->count()) {
                    continue;
                }

                $table = $fieldMapping->adjacencyTable;
                $adjacencyPk = $fieldMapping->adjacencyPk ?? 'parentId';
                $adjacencySk = $fieldMapping->adjacencySk ?? 'childId';
                $targetClass = $fieldMapping->referenceTarget;
                $targetMetadata = null !== $targetClass ? $this->metadataFactory->getMetadataFor($targetClass) : null;
                $targetPkMapping = null !== $targetMetadata ? ($targetMetadata->partitionKey ?? $targetMetadata->idField) : null;

                $writes = [];
                foreach ($added as $child) {
                    $childId = null !== $targetMetadata && null !== $targetPkMapping
                        ? (string) $targetMetadata->reflectionProperties[$targetPkMapping->propertyName]->getValue($child) // @phpstan-ignore cast.string
                        : '';
                    $key = [$adjacencyPk => $parentId, $adjacencySk => $childId];
                    $writes[] = [
                        'table' => $table,
                        'item' => $key,
                        'key' => $key,
                        'isNew' => true,
                        'fieldChanges' => [],
                    ];
                }

                $deletes = [];
                foreach ($removed as $child) {
                    $childId = null !== $targetMetadata && null !== $targetPkMapping
                        ? (string) $targetMetadata->reflectionProperties[$targetPkMapping->propertyName]->getValue($child) // @phpstan-ignore cast.string
                        : '';
                    $deletes[] = [
                        'table' => $table,
                        'key' => [$adjacencyPk => $parentId, $adjacencySk => $childId],
                    ];
                }

                $mutations[] = [
                    'collection' => $collection,
                    'writes' => $writes,
                    'deletes' => $deletes,
                ];
            }
        }

        return $mutations;
    }
}
