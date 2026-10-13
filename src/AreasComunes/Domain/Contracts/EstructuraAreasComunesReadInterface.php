<?php

namespace Src\AreasComunes\Domain\Contracts;

interface EstructuraAreasComunesReadInterface
{
    /** @param list<string> $buildingIds @return array<string, list<array<string, mixed>>> */
    public function options(array $buildingIds): array;

    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function buildings(array $buildingIds): array;

    /** @param array<string, mixed> $data @param array<string, mixed> $current */
    public function assertLocation(string $buildingId, array $data, array $current = [], bool $lock = false): void;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function location(string $buildingId, array $data): array;

    /** @param list<string> $userIds @return array<string, string> */
    public function memberNames(string $buildingId, array $userIds): array;
}
