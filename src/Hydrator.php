<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

use Likeuntomurphy\Serverless\OGM\Metadata\ClassMetadata;
use Likeuntomurphy\Serverless\OGM\Metadata\FieldMapping;
use Likeuntomurphy\Serverless\OGM\Metadata\MetadataFactory;

readonly class Hydrator
{
    private LazyGhostFactory $ghostFactory;

    /**
     * @param \Closure(class-string, Identity): ?object                                                                                                          $finder
     * @param \Closure(class-string, list<Identity>): list<object>                                                                                               $batchFinder
     * @param \Closure(string, string, string, string, int, ?array<string, mixed>, bool): array{childIds: list<string>, lastEvaluatedKey: ?array<string, mixed>} $adjacencyQuerier
     * @param \Closure(string, string, string): int                                                                                                              $adjacencyCounter
     */
    public function __construct(
        private MetadataFactory $metadataFactory,
        private \Closure $finder,
        private \Closure $batchFinder,
        private \Closure $adjacencyQuerier,
        private \Closure $adjacencyCounter,
    ) {
        $this->ghostFactory = new LazyGhostFactory($metadataFactory);
    }

    /**
     * Hydrate a DynamoDB item (already unmarshalled) into an entity.
     *
     * @param array<string, mixed> $item
     */
    public function hydrate(ClassMetadata $metadata, array $item): object
    {
        $entity = $metadata->reflectionClass->newInstanceWithoutConstructor();

        $pkMapping = $metadata->partitionKey ?? $metadata->idField;
        $parentId = null !== $pkMapping ? (string) ($item[$pkMapping->attributeName] ?? '') : ''; // @phpstan-ignore cast.string

        foreach ($metadata->fields as $fieldMapping) {
            $property = $metadata->reflectionProperties[$fieldMapping->propertyName];

            // Adjacency-table ReferenceMany: data is external, not in the item
            if ($fieldMapping->isReferenceMany() && null !== $fieldMapping->adjacencyTable) {
                $property->setValue($entity, $this->hydrateAdjacencyCollection($fieldMapping, $parentId));

                continue;
            }

            if (!array_key_exists($fieldMapping->attributeName, $item)) {
                continue;
            }

            $value = $item[$fieldMapping->attributeName];

            $value = $this->hydrateValue($fieldMapping, $property, $value);
            $property->setValue($entity, $value);
        }

        return $entity;
    }

    /**
     * Extract an entity into a DynamoDB item (plain array, pre-marshal).
     *
     * @return array<string, mixed>
     */
    public function extract(ClassMetadata $metadata, object $entity): array
    {
        $item = [];

        foreach ($metadata->fields as $fieldMapping) {
            // Adjacency-table relationships are stored externally
            if ($fieldMapping->isReferenceMany() && null !== $fieldMapping->adjacencyTable) {
                continue;
            }

            $property = $metadata->reflectionProperties[$fieldMapping->propertyName];

            if (!$property->isInitialized($entity)) {
                continue;
            }

            $value = $property->getValue($entity);

            if (null === $value) {
                continue;
            }

            $item[$fieldMapping->attributeName] = $this->extractValue($fieldMapping, $property, $value);
        }

        return $item;
    }

    private function hydrateValue(FieldMapping $mapping, \ReflectionProperty $property, mixed $value): mixed
    {
        // Reference (lazy ghost)
        if ($mapping->isReference() && $mapping->referenceTarget && is_string($value)) {
            $finder = $this->finder;

            return $this->ghostFactory->create(
                $mapping->referenceTarget,
                new Identity($value),
                fn (string $class, Identity $id) => $finder($class, $id),
            );
        }

        // Inline ReferenceMany (array of IDs → ArrayCollection of lazy ghosts)
        if ($mapping->isReferenceMany() && $mapping->referenceTarget && is_array($value)) {
            $finder = $this->finder;
            $target = $mapping->referenceTarget;
            $factory = $this->ghostFactory;

            /** @var list<string> $ids */
            $ids = array_map(fn (mixed $id): string => (string) $id, $value); // @phpstan-ignore cast.string

            $ghosts = array_map(
                fn (string $id) => $factory->create(
                    $target,
                    new Identity($id),
                    fn (string $class, Identity $id) => $finder($class, $id),
                ),
                $ids,
            );

            return new ArrayCollection($ghosts);
        }

        // EmbedOne
        if ($mapping->isEmbed() && $mapping->embedTarget && is_array($value)) {
            return $this->hydrateEmbedded($mapping->embedTarget, $value); // @phpstan-ignore argument.type
        }

        // EmbedMany
        if ($mapping->isEmbedMany() && $mapping->embedTarget && is_array($value)) {
            $target = $mapping->embedTarget;

            return array_map(
                fn (mixed $v) => $this->hydrateEmbedded($target, (array) $v), // @phpstan-ignore argument.type
                $value,
            );
        }

        // Type coercion based on property type
        return $this->coerceToPropertyType($property, $value);
    }

    private function extractValue(FieldMapping $mapping, \ReflectionProperty $property, mixed $value): mixed
    {
        // Reference → extract ID without triggering ghost
        if ($mapping->isReference() && is_object($value) && $mapping->referenceTarget) {
            return $this->extractReferenceId($mapping->referenceTarget, $value);
        }

        // Inline ReferenceMany → extract array of IDs
        if ($mapping->isReferenceMany() && $mapping->referenceTarget && $value instanceof Collection) {
            $target = $mapping->referenceTarget;

            return array_map(
                fn (object $v) => $this->extractReferenceId($target, $v),
                $value->toArray(),
            );
        }

        // EmbedOne
        if ($mapping->isEmbed() && $mapping->embedTarget && is_object($value)) {
            return $this->extractEmbedded($mapping->embedTarget, $value);
        }

        // EmbedMany
        if ($mapping->isEmbedMany() && $mapping->embedTarget && is_array($value)) {
            $target = $mapping->embedTarget;

            return array_values(array_map(
                fn (mixed $v) => is_object($v) ? $this->extractEmbedded($target, $v) : $v,
                $value,
            ));
        }

        // DateTime → ISO 8601
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        // Backed enum → value
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function hydrateAdjacencyCollection(FieldMapping $mapping, string $parentId): PersistentCollection
    {
        $adjacencyTable = $mapping->adjacencyTable ?? '';
        $adjacencyPk = $mapping->adjacencyPk ?? 'parentId';
        $adjacencySk = $mapping->adjacencySk ?? 'childId';
        $scanForward = $mapping->adjacencyScanForward;
        $target = $mapping->referenceTarget ?? '';
        $querier = $this->adjacencyQuerier;
        $counter = $this->adjacencyCounter;
        $batchFinder = $this->batchFinder;

        $idsExecutor = fn (int $limit, ?array $exclusiveStartKey): array => ($querier)($adjacencyTable, $parentId, $adjacencyPk, $adjacencySk, $limit, $exclusiveStartKey, $scanForward); // @phpstan-ignore argument.type

        return new PersistentCollection(
            queryExecutor: function (int $limit, ?array $exclusiveStartKey) use ($idsExecutor, $batchFinder, $target): array {
                $result = $idsExecutor($limit, $exclusiveStartKey);
                $identities = array_map(fn (string $childId) => new Identity($childId), $result['childIds']);
                $items = [] !== $identities ? ($batchFinder)($target, $identities) : []; // @phpstan-ignore argument.type

                return [
                    'items' => $items,
                    'childIds' => $result['childIds'],
                    'lastEvaluatedKey' => $result['lastEvaluatedKey'],
                ];
            },
            countExecutor: fn (): int => ($counter)($adjacencyTable, $parentId, $adjacencyPk),
            idsExecutor: $idsExecutor,
        );
    }

    /**
     * @param class-string         $className
     * @param array<string, mixed> $data
     */
    private function hydrateEmbedded(string $className, array $data): object
    {
        $embeddedMeta = $this->metadataFactory->getEmbeddedMetadataFor($className);
        $object = $embeddedMeta->reflectionClass->newInstanceWithoutConstructor();

        foreach ($embeddedMeta->fields as $fieldMapping) {
            if (!array_key_exists($fieldMapping->attributeName, $data)) {
                continue;
            }

            $value = $data[$fieldMapping->attributeName];
            $property = $embeddedMeta->reflectionProperties[$fieldMapping->propertyName];

            if ($fieldMapping->isEmbed() && $fieldMapping->embedTarget && is_array($value)) {
                $value = $this->hydrateEmbedded($fieldMapping->embedTarget, $value); // @phpstan-ignore argument.type
            } elseif ($fieldMapping->isEmbedMany() && $fieldMapping->embedTarget && is_array($value)) {
                $target = $fieldMapping->embedTarget;
                $value = array_map(
                    fn (mixed $v) => $this->hydrateEmbedded($target, (array) $v), // @phpstan-ignore argument.type
                    $value,
                );
            } else {
                $value = $this->coerceToPropertyType($property, $value);
            }

            $property->setValue($object, $value);
        }

        return $object;
    }

    /**
     * @param class-string $className
     *
     * @return array<string, mixed>
     */
    private function extractEmbedded(string $className, object $object): array
    {
        $embeddedMeta = $this->metadataFactory->getEmbeddedMetadataFor($className);
        $data = [];

        foreach ($embeddedMeta->fields as $fieldMapping) {
            $property = $embeddedMeta->reflectionProperties[$fieldMapping->propertyName];

            if (!$property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);
            if (null === $value) {
                continue;
            }

            if ($fieldMapping->isEmbed() && $fieldMapping->embedTarget && is_object($value)) {
                $value = $this->extractEmbedded($fieldMapping->embedTarget, $value);
            } elseif ($fieldMapping->isEmbedMany() && $fieldMapping->embedTarget && is_array($value)) {
                $target = $fieldMapping->embedTarget;
                $value = array_values(array_map(
                    fn (mixed $v) => is_object($v) ? $this->extractEmbedded($target, $v) : $v,
                    $value,
                ));
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('c');
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $data[$fieldMapping->attributeName] = $value;
        }

        return $data;
    }

    /**
     * @param class-string $targetClass
     */
    private function extractReferenceId(string $targetClass, object $value): mixed
    {
        $targetMetadata = $this->metadataFactory->getMetadataFor($targetClass);
        $idMapping = $targetMetadata->partitionKey ?? $targetMetadata->idField;
        if ($idMapping) {
            return $targetMetadata->reflectionProperties[$idMapping->propertyName]->getValue($value);
        }

        return $value;
    }

    private function coerceToPropertyType(\ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // DateTime
        if (is_string($value) && (\DateTime::class === $typeName || 'DateTimeInterface' === $typeName)) {
            return new \DateTime($value);
        }

        if (is_string($value) && \DateTimeImmutable::class === $typeName) {
            return new \DateTimeImmutable($value);
        }

        // Backed enum
        if ((is_string($value) || is_int($value)) && enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class)) {
            return $typeName::from($value);
        }

        // Numeric coercion (DynamoDB numbers come back as strings/floats)
        if ('int' === $typeName && is_numeric($value)) {
            return (int) $value;
        }

        if ('float' === $typeName && is_numeric($value)) {
            return (float) $value;
        }

        if ('bool' === $typeName) {
            return (bool) $value;
        }

        return $value;
    }
}
