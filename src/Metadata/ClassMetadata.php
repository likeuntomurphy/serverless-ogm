<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Metadata;

use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\EmbedMany;
use Likeuntomurphy\Serverless\OGM\Mapping\EmbedOne;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\Id;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;
use Likeuntomurphy\Serverless\OGM\Mapping\Reference;
use Likeuntomurphy\Serverless\OGM\Mapping\ReferenceMany;
use Likeuntomurphy\Serverless\OGM\Mapping\SortKey;

class ClassMetadata
{
    /** @var array<string, FieldMapping> keyed by property name */
    public readonly array $fields;
    public readonly string $table;
    public readonly ?FieldMapping $idField;
    public readonly ?FieldMapping $partitionKey;
    public readonly ?FieldMapping $sortKey;

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
        $docAttr = $ref->getAttributes(Document::class);

        if (!$docAttr) {
            throw new \InvalidArgumentException(sprintf('Class "%s" is not a mapped document.', $className));
        }

        $document = $docAttr[0]->newInstance();
        $this->table = $document->table;

        $fields = [];
        $idField = null;
        $partitionKey = null;
        $sortKey = null;

        foreach ($ref->getProperties() as $property) {
            $isId = (bool) $property->getAttributes(Id::class);
            $isPk = (bool) $property->getAttributes(PartitionKey::class);
            $isSk = (bool) $property->getAttributes(SortKey::class);
            $fieldAttrs = $property->getAttributes(Field::class);
            $refAttrs = $property->getAttributes(Reference::class);
            $refManyAttrs = $property->getAttributes(ReferenceMany::class);
            $embedOneAttrs = $property->getAttributes(EmbedOne::class);
            $embedManyAttrs = $property->getAttributes(EmbedMany::class);

            if (!$isId && !$isPk && !$isSk && !$fieldAttrs && !$refAttrs && !$refManyAttrs && !$embedOneAttrs && !$embedManyAttrs) {
                continue;
            }

            $referenceTarget = null;
            $isReferenceMany = false;
            $embedTarget = null;
            $isEmbedMany = false;

            if ($refAttrs) {
                $referenceTarget = $refAttrs[0]->newInstance()->targetDocument;
            } elseif ($refManyAttrs) {
                $referenceTarget = $refManyAttrs[0]->newInstance()->targetDocument;
                $isReferenceMany = true;
            }

            if ($embedOneAttrs) {
                $embedTarget = $embedOneAttrs[0]->newInstance()->targetDocument;
            } elseif ($embedManyAttrs) {
                $embedTarget = $embedManyAttrs[0]->newInstance()->targetDocument;
                $isEmbedMany = true;
            }

            // Determine the DynamoDB attribute name
            $attributeName = $property->getName();

            if ($isId) {
                $idAttr = $property->getAttributes(Id::class)[0]->newInstance();
                $attributeName = $idAttr->field ?? $attributeName;
            } elseif ($isPk) {
                $pkAttr = $property->getAttributes(PartitionKey::class)[0]->newInstance();
                $attributeName = $pkAttr->name ?? $document->pk ?? $attributeName;
            } elseif ($isSk) {
                $skAttr = $property->getAttributes(SortKey::class)[0]->newInstance();
                $attributeName = $skAttr->name ?? $document->sk ?? $attributeName;
            } elseif ($fieldAttrs) {
                $fieldInstance = $fieldAttrs[0]->newInstance();
                $attributeName = $fieldInstance->name ?? $attributeName;
            } elseif ($embedOneAttrs) {
                $attributeName = $embedOneAttrs[0]->newInstance()->name ?? $attributeName;
            } elseif ($embedManyAttrs) {
                $attributeName = $embedManyAttrs[0]->newInstance()->name ?? $attributeName;
            }

            $mapping = new FieldMapping(
                propertyName: $property->getName(),
                attributeName: $attributeName,
                isId: $isId,
                isPartitionKey: $isPk,
                isSortKey: $isSk,
                referenceTarget: $referenceTarget,
                isReferenceMany: $isReferenceMany,
                embedTarget: $embedTarget,
                isEmbedMany: $isEmbedMany,
            );

            $fields[$property->getName()] = $mapping;

            if ($isId) {
                $idField = $mapping;
            }
            if ($isPk) {
                $partitionKey = $mapping;
            }
            if ($isSk) {
                $sortKey = $mapping;
            }
        }

        $this->fields = $fields;
        $this->idField = $idField;
        $this->partitionKey = $partitionKey;
        $this->sortKey = $sortKey;

        $this->initReflection();
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return ['className', 'fields', 'table', 'idField', 'partitionKey', 'sortKey'];
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
