<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Metadata;

class MetadataFactory
{
    /** @var array<class-string, ClassMetadata> */
    private array $cache = [];

    /** @var list<class-string> */
    private array $registeredClasses = [];

    /** @var array<class-string, EmbeddedMetadata> */
    private array $embeddedCache = [];

    /**
     * @param class-string $className
     */
    public function getMetadataFor(string $className): ClassMetadata
    {
        return $this->cache[$className] ??= new ClassMetadata($className);
    }

    /**
     * @param class-string $className
     */
    public function registerMetadata(string $className, ClassMetadata $metadata): void
    {
        $this->cache[$className] = $metadata;
    }

    /**
     * @param class-string $className
     */
    public function registerClass(string $className): void
    {
        $this->registeredClasses[] = $className;
    }

    /**
     * @return array<class-string, ClassMetadata>
     */
    public function getAllMetadata(): array
    {
        foreach ($this->registeredClasses as $className) {
            if (!isset($this->cache[$className])) {
                $this->cache[$className] = new ClassMetadata($className);
            }
        }

        return $this->cache;
    }

    /**
     * @param class-string $className
     */
    public function getEmbeddedMetadataFor(string $className): EmbeddedMetadata
    {
        return $this->embeddedCache[$className] ??= new EmbeddedMetadata($className);
    }
}
