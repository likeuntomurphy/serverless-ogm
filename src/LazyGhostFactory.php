<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

use Likeuntomurphy\Serverless\OGM\Metadata\MetadataFactory;

readonly class LazyGhostFactory
{
    public function __construct(
        private MetadataFactory $metadataFactory,
    ) {
    }

    /**
     * Create a lazy ghost with the ID set eagerly (no fetch).
     * All other properties trigger initialization via the resolver on first access.
     *
     * @template T of object
     *
     * @param class-string<T>                         $className
     * @param callable(class-string, string): ?object $resolver  called when the ghost initializes
     *
     * @return T
     */
    public function create(string $className, string $id, callable $resolver): object
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $idMapping = $metadata->partitionKey ?? $metadata->idField;

        $reflector = new \ReflectionClass($className);

        $ghost = $reflector->newLazyGhost(function (object $ghost) use ($className, $id, $resolver, $reflector): void {
            $real = $resolver($className, $id);

            if (null === $real) {
                throw new \RuntimeException(sprintf('Referenced %s "%s" not found.', $className, $id));
            }

            foreach ($reflector->getProperties() as $property) {
                if ($property->isInitialized($real)) {
                    $property->setValue($ghost, $property->getValue($real));
                }
            }
        });

        // Set the ID eagerly — accessing it won't trigger initialization
        if ($idMapping) {
            $reflector->getProperty($idMapping->propertyName)
                ->setRawValueWithoutLazyInitialization($ghost, $id)
            ;
        }

        return $ghost;
    }
}
