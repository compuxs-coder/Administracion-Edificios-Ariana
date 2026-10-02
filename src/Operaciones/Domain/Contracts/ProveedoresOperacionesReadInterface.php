<?php

namespace Src\Operaciones\Domain\Contracts;

interface ProveedoresOperacionesReadInterface
{
    /** @param list<string> $buildingIds @return array<string, list<array<string, mixed>>> */
    public function options(array $buildingIds): array;

    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function providers(array $buildingIds): array;

    /** @return array<string, mixed>|null */
    public function activeProvider(string $buildingId, string $providerId, bool $lockForUpdate = false): ?array;

    /** @return array<string, mixed>|null */
    public function provider(string $buildingId, string $providerId): ?array;

    /** @return array<string, mixed>|null */
    public function registeredContract(string $buildingId, string $providerId, string $contractId, bool $lockForUpdate = false): ?array;

    /** @return array<string, mixed>|null */
    public function contract(string $buildingId, string $providerId, string $contractId): ?array;
}
