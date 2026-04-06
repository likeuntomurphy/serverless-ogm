<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

class IdentityMap
{
    /** @var array<string, array<string, object>> keyed by class => id => entity */
    private array $map = [];

    public function get(string $className, string $id): ?object
    {
        return $this->map[$className][$id] ?? null;
    }

    public function put(string $className, string $id, object $entity): void
    {
        $this->map[$className][$id] = $entity;
    }

    public function remove(string $className, string $id): void
    {
        unset($this->map[$className][$id]);
    }

    public static function compositeKey(string $pk, ?string $sk = null): string
    {
        return null === $sk ? $pk : $pk."\0".$sk;
    }

    public function clear(): void
    {
        $this->map = [];
    }
}
