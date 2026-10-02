<?php

namespace Src\Operaciones\Domain\Contracts;

interface ReportantesOperacionesReadInterface
{
    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function options(array $buildingIds): array;

    /** @return array<string, mixed>|null */
    public function activeSnapshot(string $buildingId, string $residentId, bool $lockForUpdate = false): ?array;
}
