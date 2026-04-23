<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Metadata;

readonly class FieldMapping
{
    /**
     * @param null|class-string $referenceTarget
     * @param null|class-string $embedTarget
     */
    public function __construct(
        public string $propertyName,
        public string $attributeName,
        public bool $isId = false,
        public bool $isPartitionKey = false,
        public bool $isSortKey = false,
        public ?string $referenceTarget = null,
        private bool $isReferenceMany = false,
        public ?string $embedTarget = null,
        private bool $isEmbedMany = false,
        public ?string $adjacencyTable = null,
        public ?string $adjacencyPk = null,
        public ?string $adjacencySk = null,
        public bool $adjacencyScanForward = true,
    ) {
    }

    public function isReference(): bool
    {
        return null !== $this->referenceTarget && !$this->isReferenceMany;
    }

    public function isReferenceMany(): bool
    {
        return null !== $this->referenceTarget && $this->isReferenceMany;
    }

    public function isEmbed(): bool
    {
        return null !== $this->embedTarget && !$this->isEmbedMany;
    }

    public function isEmbedMany(): bool
    {
        return null !== $this->embedTarget && $this->isEmbedMany;
    }
}
