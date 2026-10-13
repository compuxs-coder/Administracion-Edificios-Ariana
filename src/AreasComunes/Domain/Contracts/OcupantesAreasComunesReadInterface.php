<?php

namespace Src\AreasComunes\Domain\Contracts;

interface OcupantesAreasComunesReadInterface
{
    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function options(array $buildingIds): array;

    /** @return array<string, mixed>|null */
    public function eligibleOccupation(string $buildingId, string $occupationId, string $localDate, bool $lock = false): ?array;
}
