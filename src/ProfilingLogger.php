<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

interface ProfilingLogger
{
    public function recordIdentityMapHit(): void;

    public function recordIdentityMapMiss(): void;

    public function recordHydration(): void;
}
