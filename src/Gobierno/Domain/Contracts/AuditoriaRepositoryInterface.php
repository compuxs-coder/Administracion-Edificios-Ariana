<?php

namespace Src\Gobierno\Domain\Contracts;

interface AuditoriaRepositoryInterface
{
    /** @template T @param callable(): T $reader @return T */
    public function withinConsistentExportSnapshot(callable $reader): mixed;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @param list<string> $selectedBuildingIds @return array<string, mixed> */
    public function options(string $userId, array $selectedBuildingIds = []): array;

    /** @param list<string> $buildingIds @return list<array<string, mixed>> */
    public function exportScopeForUser(string $userId, array $buildingIds, ?string $source, ?string $eventType): array;

    /** @param array<string, mixed> $filters @param array<string, string>|null $cursor @return array<string, mixed> */
    public function exportChunkForUser(string $userId, array $filters, ?array $cursor, int $limit): array;
}
