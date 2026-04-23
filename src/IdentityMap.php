<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

class IdentityMap
{
    /** @var array<string, array<string, array<string, object>>> class => pk => sk => entity */
    private array $map = [];

    public function get(string $className, Identity $id): ?object
    {
        return $this->map[$className][$id->pk][$id->sk ?? ''] ?? null;
    }

    public function put(string $className, Identity $id, object $entity): void
    {
        $this->map[$className][$id->pk][$id->sk ?? ''] = $entity;
    }

    public function remove(string $className, Identity $id): void
    {
        unset($this->map[$className][$id->pk][$id->sk ?? '']);
    }

    public function clear(): void
    {
        $this->map = [];
    }
}
