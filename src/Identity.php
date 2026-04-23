<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

final readonly class Identity
{
    public function __construct(
        public string $pk,
        public ?string $sk = null,
    ) {
    }

    public static function of(string $pk, ?string $sk = null): self
    {
        return new self($pk, $sk);
    }

    public function equals(self $other): bool
    {
        return $this->pk === $other->pk && $this->sk === $other->sk;
    }

    public function __toString(): string
    {
        return null === $this->sk ? $this->pk : $this->pk.'/'.$this->sk;
    }
}
