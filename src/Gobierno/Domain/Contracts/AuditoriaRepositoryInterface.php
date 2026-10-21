<?php

namespace Src\Gobierno\Domain\Contracts;

interface AuditoriaRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @param list<string> $selectedBuildingIds @return array<string, mixed> */
    public function options(string $userId, array $selectedBuildingIds = []): array;
}
