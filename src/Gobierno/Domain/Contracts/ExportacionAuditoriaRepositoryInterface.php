<?php

namespace Src\Gobierno\Domain\Contracts;

use Throwable;

interface ExportacionAuditoriaRepositoryInterface
{
    /** @param array<string, mixed> $data @param list<array<string, mixed>> $scope @return array<string, mixed> */
    public function request(string $userId, array $data, array $scope): array;

    /** @return array<string, mixed>|null */
    public function claim(string $exportId): ?array;

    public function renewProgress(string $exportId, string $leaseToken, int $processed): bool;

    /** @param array<string, mixed> $file */
    public function complete(string $exportId, string $leaseToken, array $file, int $total): bool;

    public function fail(string $exportId, string $leaseToken, Throwable $exception): void;

    /** @return list<string> */
    public function dueIds(int $limit = 100): array;

    /** @return list<array<string, mixed>> */
    public function recentForUser(string $userId, int $limit = 20): array;

    /** @return array<string, string> */
    public function downloadForUser(string $userId, string $exportId): array;

    public function registerCleanupIntent(string $exportId, string $leaseToken, string $disk, string $path): void;

    public function forgetCleanupIntent(string $disk, string $path): void;

    /** @return array{expired: int, deleted: int, errors: int} */
    public function cleanupExpired(int $limit = 500): array;
}
