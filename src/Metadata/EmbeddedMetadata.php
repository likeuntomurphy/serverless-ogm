<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Metadata;

use Likeuntomurphy\Serverless\OGM\Mapping\EmbedMany;
use Likeuntomurphy\Serverless\OGM\Mapping\EmbedOne;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;

class EmbeddedMetadata
{
    /** @var array<string, FieldMapping> keyed by property name */
    public readonly array $fields;

    /** @var \ReflectionClass<object> */
    public \ReflectionClass $reflectionClass;

    /** @var array<string, \ReflectionProperty> keyed by property name */
    public array $reflectionProperties = [];

    /**
     * @param class-string $className
     */
    public function __construct(
        public readonly string $className,
    ) {
        $ref = new \ReflectionClass($className);

        $fields = [];
        foreach ($ref->getProperties() as $property) {
            $fieldAttrs = $property->getAttributes(Field::class);
            $embedOneAttrs = $property->getAttributes(EmbedOne::class);
            $embedManyAttrs = $property->getAttributes(EmbedMany::class);

            if (!$fieldAttrs && !$embedOneAttrs && !$embedManyAttrs) {
                continue;
            }

            $attributeName = $property->getName();
            $embedTarget = null;
            $isEmbedMany = false;

            if ($fieldAttrs) {
                $attributeName = $fieldAttrs[0]->newInstance()->name ?? $attributeName;
            } elseif ($embedOneAttrs) {
                $instance = $embedOneAttrs[0]->newInstance();
                $attributeName = $instance->name ?? $attributeName;
                $embedTarget = $instance->targetDocument;
            } elseif ($embedManyAttrs) {
                $instance = $embedManyAttrs[0]->newInstance();
                $attributeName = $instance->name ?? $attributeName;
                $embedTarget = $instance->targetDocument;
                $isEmbedMany = true;
            }

            $fields[$property->getName()] = new FieldMapping(
                propertyName: $property->getName(),
                attributeName: $attributeName,
                embedTarget: $embedTarget,
                isEmbedMany: $isEmbedMany,
            );
        }

        $this->fields = $fields;

        $this->initReflection();
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return ['className', 'fields'];
    }

    public function __wakeup(): void
    {
        $this->initReflection();
    }

    private function initReflection(): void
    {
        $this->reflectionClass = new \ReflectionClass($this->className);
        $this->reflectionProperties = [];

        foreach ($this->fields as $fieldMapping) {
            $this->reflectionProperties[$fieldMapping->propertyName] = $this->reflectionClass->getProperty($fieldMapping->propertyName);
        }
    }
}
