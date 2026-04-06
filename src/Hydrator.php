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
     * @param \Closure(class-string, string): ?object            $finder
     * @param \Closure(class-string, list<string>): list<object> $batchFinder
     */
    public function __construct(
        private MetadataFactory $metadataFactory,
        private \Closure $finder,
        private \Closure $batchFinder,
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

        foreach ($metadata->fields as $fieldMapping) {
            if (!array_key_exists($fieldMapping->attributeName, $item)) {
                continue;
            }

            $value = $item[$fieldMapping->attributeName];
            $property = $metadata->reflectionProperties[$fieldMapping->propertyName];

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
                $value,
                fn (string $class, string $id) => $finder($class, $id),
            );
        }

        // ReferenceMany (array of IDs → lazy collection with BatchGetItem)
        if ($mapping->isReferenceMany() && $mapping->referenceTarget && is_array($value)) {
            $batchFinder = $this->batchFinder;
            $finder = $this->finder;
            $target = $mapping->referenceTarget;
            $factory = $this->ghostFactory;

            /** @var list<string> $ids */
            $ids = array_map(fn (mixed $id): string => (string) $id, $value); // @phpstan-ignore cast.string

            // For array-typed properties, use lazy ghosts to avoid circular hydration
            $propertyType = $property->getType();
            if ($propertyType instanceof \ReflectionNamedType && 'array' === $propertyType->getName()) {
                return array_map(
                    fn (string $id) => $factory->create(
                        $target,
                        $id,
                        fn (string $class, string $id) => $finder($class, $id),
                    ),
                    $ids,
                );
            }

            return new Collection(
                $ids,
                fn (array $ids): array => ($batchFinder)($target, $ids),
            );
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

        // ReferenceMany → extract array of IDs
        if ($mapping->isReferenceMany() && $mapping->referenceTarget) {
            $target = $mapping->referenceTarget;
            $items = $value instanceof Collection ? $value->toArray() : (array) $value;

            return array_map(
                fn (mixed $v) => is_object($v) ? $this->extractReferenceId($target, $v) : $v,
                $items,
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
