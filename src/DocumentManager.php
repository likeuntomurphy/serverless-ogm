<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Likeuntomurphy\Serverless\OGM\Event\PostFlushEvent;
use Likeuntomurphy\Serverless\OGM\Event\PostPersistEvent;
use Likeuntomurphy\Serverless\OGM\Event\PostRemoveEvent;
use Likeuntomurphy\Serverless\OGM\Event\PostUpdateEvent;
use Likeuntomurphy\Serverless\OGM\Event\PrePersistEvent;
use Likeuntomurphy\Serverless\OGM\Event\PreRemoveEvent;
use Likeuntomurphy\Serverless\OGM\Event\PreUpdateEvent;
use Likeuntomurphy\Serverless\OGM\FlushStrategy\BatchWriteStrategy;
use Likeuntomurphy\Serverless\OGM\FlushStrategy\FlushStrategyInterface;
use Likeuntomurphy\Serverless\OGM\Metadata\MetadataFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

class DocumentManager
{
    private readonly MetadataFactory $metadataFactory;
    private readonly Hydrator $hydrator;
    private readonly Marshaler $marshaler;
    private readonly IdentityMap $identityMap;
    private readonly UnitOfWork $unitOfWork;
    private readonly FlushStrategyInterface $flushStrategy;

    private ?ProfilingLogger $profilingLogger = null;

    /** @var array<string, array<string, list<string>>> table => parentId => childIds */
    private array $relationCache = [];

    public function __construct(
        private readonly DynamoDbClient $client,
        ?MetadataFactory $metadataFactory = null,
        private readonly string $tableSuffix = '',
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?FlushStrategyInterface $flushStrategy = null,
    ) {
        $this->metadataFactory = $metadataFactory ?? new MetadataFactory();
        $this->hydrator = new Hydrator(
            $this->metadataFactory,
            $this->find(...),
            $this->batchFind(...),
            $this->queryAdjacencyTable(...),
            $this->countAdjacencyTable(...),
        );
        $this->marshaler = new Marshaler(['nullify_invalid' => true]);
        $this->identityMap = new IdentityMap();
        $this->unitOfWork = new UnitOfWork($this->metadataFactory, $this->hydrator);
        $this->flushStrategy = $flushStrategy ?? new BatchWriteStrategy($this->client, $this->marshaler, $this->tableSuffix);
    }

    public function setProfilingLogger(?ProfilingLogger $logger): void
    {
        $this->profilingLogger = $logger;
    }

    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadataFactory;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return null|T
     */
    public function find(string $className, Identity $id): ?object
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);

        if (null !== $metadata->sortKey && null === $id->sk) {
            throw new \InvalidArgumentException(sprintf('Document "%s" has a sort key — Identity must include one.', $className));
        }

        $existing = $this->identityMap->get($className, $id);
        if (null !== $existing) {
            $this->profilingLogger?->recordIdentityMapHit();

            /** @var T $existing */
            return $existing;
        }
        $this->profilingLogger?->recordIdentityMapMiss();

        $pkMapping = $metadata->partitionKey ?? $metadata->idField;
        if (null === $pkMapping) {
            throw new \LogicException(sprintf('No key field defined on "%s".', $className));
        }

        $key = [$pkMapping->attributeName => $id->pk];
        if (null !== $metadata->sortKey && null !== $id->sk) {
            $key[$metadata->sortKey->attributeName] = $id->sk;
        }

        $result = $this->client->getItem([
            'TableName' => $this->tableName($metadata->table),
            'Key' => $this->marshaler->marshalItem($key),
        ]);

        if (!$result['Item']) {
            return null;
        }

        /** @var array<string, mixed> $item */
        $item = $this->marshaler->unmarshalItem((array) $result['Item']);
        $entity = $this->hydrator->hydrate($metadata, $item);
        $this->profilingLogger?->recordHydration();

        $this->identityMap->put($className, $id, $entity);
        $this->unitOfWork->registerManaged($entity, $item);

        /** @var T $entity */
        return $entity;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     * @param list<Identity>  $ids
     *
     * @return list<T>
     */
    public function batchFind(string $className, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $metadata = $this->metadataFactory->getMetadataFor($className);
        $pkMapping = $metadata->partitionKey ?? $metadata->idField;

        if (!$pkMapping) {
            throw new \LogicException(sprintf('No key field defined on "%s".', $className));
        }

        // Check identity map first
        $results = [];
        $missing = [];

        foreach ($ids as $i => $id) {
            $existing = $this->identityMap->get($className, $id);
            if (null !== $existing) {
                $this->profilingLogger?->recordIdentityMapHit();

                /** @var T $existing */
                $results[$i] = $existing;
            } else {
                $this->profilingLogger?->recordIdentityMapMiss();
                $missing[$i] = $id;
            }
        }

        if ([] === $missing) {
            return array_values($results);
        }

        // BatchGetItem: max 100 keys per request
        $tableName = $this->tableName($metadata->table);

        foreach (array_chunk($missing, 100, true) as $chunk) {
            $keys = array_map(
                fn (Identity $id): array => $this->marshaler->marshalItem(
                    null !== $id->sk && null !== $metadata->sortKey
                        ? [$pkMapping->attributeName => $id->pk, $metadata->sortKey->attributeName => $id->sk]
                        : [$pkMapping->attributeName => $id->pk],
                ),
                $chunk,
            );

            $requestItems = [$tableName => ['Keys' => array_values($keys)]];

            for ($attempt = 0; $attempt <= 3; ++$attempt) {
                $result = $this->client->batchGetItem(['RequestItems' => $requestItems]);

                /** @var array<string, list<array<string, mixed>>> $allResponses */
                $allResponses = $result['Responses'] ?? [];
                $responses = $allResponses[$tableName] ?? [];

                foreach ($responses as $rawItem) {
                    /** @var array<string, mixed> $item */
                    $item = $this->marshaler->unmarshalItem((array) $rawItem);
                    $entity = $this->hydrator->hydrate($metadata, $item);
                    $this->profilingLogger?->recordHydration();

                    $itemIdentity = $this->resolveIdentity($className, $item);
                    if (null === $itemIdentity) {
                        continue;
                    }

                    $this->identityMap->put($className, $itemIdentity, $entity);
                    $this->unitOfWork->registerManaged($entity, $item);

                    // Match back to original index
                    foreach ($chunk as $origIndex => $origId) {
                        if ($origId->equals($itemIdentity)) {
                            /** @var T $entity */
                            $results[$origIndex] = $entity;
                            unset($chunk[$origIndex]);

                            break;
                        }
                    }
                }

                /** @var array<string, array{Keys: list<array<string, mixed>>}> $unprocessedKeys */
                $unprocessedKeys = $result['UnprocessedKeys'] ?? [];

                if ([] === $unprocessedKeys) {
                    break;
                }

                $requestItems = $unprocessedKeys;

                if ($attempt < 3) {
                    usleep(50000 * (2 ** $attempt));
                }
            }
        }

        ksort($results);

        /** @var list<T> */
        return array_values($results);
    }

    /**
     * Take a raw (unmarshaled) DynamoDB item produced by an app-owned Query/Scan
     * and return a managed entity: hydrated, snapshotted for dirty tracking,
     * registered with the UnitOfWork, and inserted into the IdentityMap.
     *
     * If an entity with the same identity is already managed, the existing
     * instance is returned unchanged — the item is not re-hydrated.
     *
     * @template T of object
     *
     * @param class-string<T>      $className
     * @param array<string, mixed> $item      unmarshaled item (run Marshaler::unmarshalItem first)
     *
     * @return T
     */
    public function attach(string $className, array $item): object
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);

        $identity = $this->resolveIdentity($className, $item);
        if (null === $identity) {
            $idMapping = $metadata->partitionKey ?? $metadata->idField;
            $missing = $idMapping ? $idMapping->attributeName : '(no key field defined)';

            throw new \InvalidArgumentException(sprintf('Cannot attach item to "%s": missing or non-scalar partition key attribute "%s".', $className, $missing));
        }

        if (null !== $metadata->sortKey && null === $identity->sk) {
            throw new \InvalidArgumentException(sprintf('Cannot attach item to "%s": missing or non-scalar sort key attribute "%s".', $className, $metadata->sortKey->attributeName));
        }

        $existing = $this->identityMap->get($className, $identity);
        if (null !== $existing) {
            /** @var T $existing */
            return $existing;
        }

        $entity = $this->hydrator->hydrate($metadata, $item);

        $this->identityMap->put($className, $identity, $entity);
        $this->unitOfWork->registerManaged($entity, $item);

        /** @var T $entity */
        return $entity;
    }

    /**
     * Attach many raw items in one call. Duplicate identities dedupe via the IdentityMap.
     *
     * @template T of object
     *
     * @param class-string<T>            $className
     * @param list<array<string, mixed>> $items
     *
     * @return list<T>
     */
    public function attachAll(string $className, array $items): array
    {
        $entities = [];
        foreach ($items as $item) {
            $entities[] = $this->attach($className, $item);
        }

        return $entities;
    }

    public function persist(object $entity): void
    {
        $this->unitOfWork->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity);
    }

    public function flush(): void
    {
        $changeset = $this->unitOfWork->getChangeset();
        $removals = $this->unitOfWork->getRemovals();

        // Dispatch pre-events
        foreach ($changeset as $change) {
            if ($change['isNew']) {
                $this->dispatch(new PrePersistEvent($change['entity'], $change['table']));
            } else {
                $this->dispatch(new PreUpdateEvent($change['entity'], $change['table'], $change['fieldChanges']));
            }
        }

        foreach ($removals as $removal) {
            $this->dispatch(new PreRemoveEvent($removal['entity'], $removal['table']));
        }

        // Build writes and deletes for the strategy
        $writes = [];
        foreach ($changeset as $change) {
            $metadata = $this->metadataFactory->getMetadataFor($change['entity']::class);
            $writes[] = [
                'table' => $change['table'],
                'item' => $change['item'],
                'key' => $this->extractKey($metadata, $change['item']),
                'isNew' => $change['isNew'],
                'fieldChanges' => $change['fieldChanges'],
            ];
        }

        $deletes = [];
        foreach ($removals as $removal) {
            $deletes[] = ['table' => $removal['table'], 'key' => $removal['key']];
        }

        // Fold in adjacency-table operations from PersistentCollection mutations
        $adjacencyMutations = $this->unitOfWork->getAdjacencyMutations();

        /** @var list<array{collection: PersistentCollection, writeIndices: list<int>, deleteIndices: list<int>}> $adjacencyOwnership */
        $adjacencyOwnership = [];
        foreach ($adjacencyMutations as $mutation) {
            $writeIndices = [];
            foreach ($mutation['writes'] as $write) {
                $writeIndices[] = \count($writes);
                $writes[] = $write;
            }
            $deleteIndices = [];
            foreach ($mutation['deletes'] as $delete) {
                $deleteIndices[] = \count($deletes);
                $deletes[] = $delete;
            }
            $adjacencyOwnership[] = [
                'collection' => $mutation['collection'],
                'writeIndices' => $writeIndices,
                'deleteIndices' => $deleteIndices,
            ];
        }

        // Execute
        $result = $this->flushStrategy->execute($writes, $deletes);

        // Clear mutations on collections whose writes and deletes all succeeded
        $succeededWriteSet = array_flip($result->succeededWriteIndices);
        $succeededDeleteSet = array_flip($result->succeededDeleteIndices);
        foreach ($adjacencyOwnership as $owned) {
            $allSucceeded = true;
            foreach ($owned['writeIndices'] as $i) {
                if (!isset($succeededWriteSet[$i])) {
                    $allSucceeded = false;

                    break;
                }
            }
            if ($allSucceeded) {
                foreach ($owned['deleteIndices'] as $i) {
                    if (!isset($succeededDeleteSet[$i])) {
                        $allSucceeded = false;

                        break;
                    }
                }
            }
            if ($allSucceeded) {
                $owned['collection']->clearMutations();
            }
        }

        // Dispatch post-events and update identity map for succeeded writes
        foreach ($result->succeededWriteIndices as $i) {
            $change = $changeset[$i];

            if ($change['isNew']) {
                $this->dispatch(new PostPersistEvent($change['entity'], $change['table']));
                $this->registerInIdentityMap($change['entity'], $change['item']);
            } else {
                $this->dispatch(new PostUpdateEvent($change['entity'], $change['table'], $change['fieldChanges']));
            }
        }

        foreach ($result->succeededDeleteIndices as $i) {
            $removal = $removals[$i];
            $this->removeFromIdentityMap($removal['entity'], $removal['key']);
            $this->dispatch(new PostRemoveEvent($removal['entity'], $removal['table']));
        }

        $this->unitOfWork->postFlush($result, $changeset, $removals);
        $this->dispatch(new PostFlushEvent());
    }

    public function clear(): void
    {
        $this->identityMap->clear();
        $this->unitOfWork->clear();
        $this->relationCache = [];
    }

    public function tableName(string $table): string
    {
        return $table.$this->tableSuffix;
    }

    public function getClient(): DynamoDbClient
    {
        return $this->client;
    }

    /**
     * Preload all relationships for an adjacency table into an internal cache.
     * Subsequent PersistentCollection queries for these relationships
     * will be served from the cache instead of hitting DynamoDB.
     *
     * @param class-string $className The document class with the ReferenceMany field
     * @param string       $fieldName The property name of the ReferenceMany field
     */
    public function preloadRelations(string $className, string $fieldName): void
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $fieldMapping = $metadata->fields[$fieldName] ?? null;

        if (null === $fieldMapping || !$fieldMapping->isReferenceMany() || null === $fieldMapping->adjacencyTable) {
            return;
        }

        $tableName = $this->tableName($fieldMapping->adjacencyTable);
        $pkAttr = $fieldMapping->adjacencyPk ?? 'parentId';
        $skAttr = $fieldMapping->adjacencySk ?? 'childId';

        $cache = [];
        $params = ['TableName' => $tableName];

        do {
            $result = $this->client->scan($params);

            /** @var list<array<string, mixed>> $items */
            $items = $result['Items'] ?? [];
            foreach ($items as $item) {
                /** @var array<string, mixed> $pkRaw */
                $pkRaw = $item[$pkAttr] ?? [];

                /** @var array<string, mixed> $skRaw */
                $skRaw = $item[$skAttr] ?? [];

                /** @var string $parentId */
                $parentId = $this->marshaler->unmarshalValue($pkRaw);

                /** @var string $childId */
                $childId = $this->marshaler->unmarshalValue($skRaw);
                $cache[$parentId][] = $childId;
            }
            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'] ?? null;
        } while (isset($params['ExclusiveStartKey']));

        $this->relationCache[$tableName] = $cache;
    }

    /**
     * Get cached child IDs for a parent, or null if not preloaded.
     *
     * @return ?list<string>
     */
    public function getCachedRelation(string $table, string $parentId): ?array
    {
        $tableName = $this->tableName($table);

        if (!isset($this->relationCache[$tableName])) {
            return null;
        }

        return $this->relationCache[$tableName][$parentId] ?? [];
    }

    /**
     * Query an adjacency table by partition key, returning child IDs.
     *
     * @param ?array<string, mixed> $exclusiveStartKey
     *
     * @return array{childIds: list<string>, lastEvaluatedKey: ?array<string, mixed>}
     */
    public function queryAdjacencyTable(
        string $table,
        string $parentId,
        string $pkAttr,
        string $skAttr,
        int $limit,
        ?array $exclusiveStartKey = null,
        bool $scanForward = true,
    ): array {
        if (null === $exclusiveStartKey) {
            $cached = $this->getCachedRelation($table, $parentId);
            if (null !== $cached) {
                $childIds = $limit < \PHP_INT_MAX ? \array_slice($cached, 0, $limit) : $cached;

                return ['childIds' => $childIds, 'lastEvaluatedKey' => null];
            }
        }

        $params = [
            'TableName' => $this->tableName($table),
            'KeyConditionExpression' => '#pk = :pk',
            'ExpressionAttributeNames' => ['#pk' => $pkAttr],
            'ExpressionAttributeValues' => [':pk' => $this->marshaler->marshalValue($parentId)],
            'ScanIndexForward' => $scanForward,
        ];

        if ($limit < \PHP_INT_MAX) {
            $params['Limit'] = $limit;
        }

        if (null !== $exclusiveStartKey) {
            $params['ExclusiveStartKey'] = $exclusiveStartKey;
        }

        $result = $this->client->query($params);

        /** @var list<array<string, mixed>> $items */
        $items = $result['Items'] ?? [];
        $childIds = [];
        foreach ($items as $item) {
            /** @var array<string, mixed> $skRaw */
            $skRaw = $item[$skAttr] ?? ['S' => ''];

            /** @var string $childId */
            $childId = $this->marshaler->unmarshalValue($skRaw);
            $childIds[] = $childId;
        }

        /** @var ?array<string, mixed> $lastKey */
        $lastKey = $result['LastEvaluatedKey'] ?? null;

        return [
            'childIds' => $childIds,
            'lastEvaluatedKey' => $lastKey,
        ];
    }

    /**
     * Count items in an adjacency table partition.
     */
    public function countAdjacencyTable(string $table, string $parentId, string $pkAttr): int
    {
        $result = $this->client->query([
            'TableName' => $this->tableName($table),
            'KeyConditionExpression' => '#pk = :pk',
            'ExpressionAttributeNames' => ['#pk' => $pkAttr],
            'ExpressionAttributeValues' => [':pk' => $this->marshaler->marshalValue($parentId)],
            'Select' => 'COUNT',
        ]);

        return (int) ($result['Count'] ?? 0); // @phpstan-ignore cast.int
    }

    /** @param array<string, mixed> $item */
    private function registerInIdentityMap(object $entity, array $item): void
    {
        $identity = $this->resolveIdentity($entity::class, $item);
        if (null !== $identity) {
            $this->identityMap->put($entity::class, $identity, $entity);
        }
    }

    /** @param array<string, mixed> $key */
    private function removeFromIdentityMap(object $entity, array $key): void
    {
        $identity = $this->resolveIdentity($entity::class, $key);
        if (null !== $identity) {
            $this->identityMap->remove($entity::class, $identity);
        }
    }

    /**
     * @param class-string         $className
     * @param array<string, mixed> $item
     */
    private function resolveIdentity(string $className, array $item): ?Identity
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $idMapping = $metadata->partitionKey ?? $metadata->idField;
        if (!$idMapping) {
            return null;
        }

        $pk = $item[$idMapping->attributeName] ?? null;
        if (!is_string($pk) && !is_int($pk)) {
            return null;
        }

        $sk = null;
        if ($metadata->sortKey) {
            $skVal = $item[$metadata->sortKey->attributeName] ?? null;
            $sk = is_string($skVal) || is_int($skVal) ? (string) $skVal : null;
        }

        return new Identity((string) $pk, $sk);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function extractKey(Metadata\ClassMetadata $metadata, array $item): array
    {
        $key = [];
        $idMapping = $metadata->partitionKey ?? $metadata->idField;
        if ($idMapping) {
            $key[$idMapping->attributeName] = $item[$idMapping->attributeName] ?? null;
        }
        if ($metadata->sortKey) {
            $key[$metadata->sortKey->attributeName] = $item[$metadata->sortKey->attributeName] ?? null;
        }

        return $key;
    }

    private function dispatch(object $event): void
    {
        $this->eventDispatcher?->dispatch($event);
    }
}
