<?php

namespace Src\Operaciones\Domain\Contracts;

interface EstructuraMiembrosOperacionesReadInterface
{
    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function buildings(array $buildingIds): array;

    /** @param list<string> $buildingIds @return array<string, mixed> */
    public function options(array $buildingIds): array;

    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function members(array $buildingIds): array;

    /** @param array<string, mixed> $location @param array<string, mixed> $currentLocation */
    public function assertLocation(string $buildingId, array $location, array $currentLocation = []): void;

    /** @param array<string, mixed> $location @return array{tipo: string, id: string, etiqueta: string}|null */
    public function location(string $buildingId, array $location): ?array;

    /** @return array<string, mixed>|null */
    public function activeMember(string $buildingId, string $userId, bool $lockForUpdate = false): ?array;

    /** @param list<string> $userIds @return array<string, string> */
    public function memberNames(string $buildingId, array $userIds): array;
}
