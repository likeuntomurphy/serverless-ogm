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
            fn (string $class, string $id): ?object => $this->find($class, $id),
            fn (string $class, array $ids): array => $this->batchFind($class, $ids),
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
    public function find(string $className, string $id, ?string $sortKey = null): ?object
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);

        if (null !== $metadata->sortKey && null === $sortKey) {
            throw new \InvalidArgumentException(sprintf('Document "%s" has a sort key — you must pass a $sortKey to find().', $className));
        }

        $identityId = IdentityMap::compositeKey($id, $sortKey);
        $existing = $this->identityMap->get($className, $identityId);
        if (null !== $existing) {
            $this->profilingLogger?->recordIdentityMapHit();

            /** @var T $existing */
            return $existing;
        }
        $this->profilingLogger?->recordIdentityMapMiss();

        $key = [];
        if ($metadata->partitionKey) {
            $key[$metadata->partitionKey->attributeName] = $id;
        } elseif ($metadata->idField) {
            $key[$metadata->idField->attributeName] = $id;
        } else {
            throw new \LogicException(sprintf('No key field defined on "%s".', $className));
        }

        if (null !== $metadata->sortKey && null !== $sortKey) {
            $key[$metadata->sortKey->attributeName] = $sortKey;
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

        $this->identityMap->put($className, $identityId, $entity);
        $this->unitOfWork->registerManaged($entity, $item);

        /** @var T $entity */
        return $entity;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     * @param list<string>    $ids       partition key values
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
        $missingIds = [];

        foreach ($ids as $i => $id) {
            $identityId = IdentityMap::compositeKey($id);
            $existing = $this->identityMap->get($className, $identityId);
            if (null !== $existing) {
                $this->profilingLogger?->recordIdentityMapHit();

                /** @var T $existing */
                $results[$i] = $existing;
            } else {
                $this->profilingLogger?->recordIdentityMapMiss();
                $missingIds[$i] = $id;
            }
        }

        if ([] === $missingIds) {
            return array_values($results);
        }

        // BatchGetItem: max 100 keys per request
        $tableName = $this->tableName($metadata->table);

        foreach (array_chunk($missingIds, 100, true) as $chunk) {
            $keys = array_map(
                fn (string $id) => $this->marshaler->marshalItem([$pkMapping->attributeName => $id]),
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

                    $itemId = $item[$pkMapping->attributeName] ?? null;
                    if (!is_string($itemId) && !is_int($itemId)) {
                        continue;
                    }
                    $itemId = (string) $itemId;

                    $identityId = IdentityMap::compositeKey($itemId);
                    $this->identityMap->put($className, $identityId, $entity);
                    $this->unitOfWork->registerManaged($entity, $item);

                    // Match back to original index
                    foreach ($chunk as $origIndex => $origId) {
                        if ($origId === $itemId) {
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

        // Execute
        $result = $this->flushStrategy->execute($writes, $deletes);

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
    }

    public function tableName(string $table): string
    {
        return $table.$this->tableSuffix;
    }

    public function getClient(): DynamoDbClient
    {
        return $this->client;
    }

    /** @param array<string, mixed> $item */
    private function registerInIdentityMap(object $entity, array $item): void
    {
        $identityId = $this->resolveIdentityId($entity::class, $item);
        if (null !== $identityId) {
            $this->identityMap->put($entity::class, $identityId, $entity);
        }
    }

    /** @param array<string, mixed> $key */
    private function removeFromIdentityMap(object $entity, array $key): void
    {
        $identityId = $this->resolveIdentityId($entity::class, $key);
        if (null !== $identityId) {
            $this->identityMap->remove($entity::class, $identityId);
        }
    }

    /**
     * @param class-string         $className
     * @param array<string, mixed> $item
     */
    private function resolveIdentityId(string $className, array $item): ?string
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

        return IdentityMap::compositeKey((string) $pk, $sk);
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
