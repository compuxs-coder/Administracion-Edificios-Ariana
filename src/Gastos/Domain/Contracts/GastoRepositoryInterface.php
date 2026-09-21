<?php

namespace Src\Gastos\Domain\Contracts;

interface GastoRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $gastoId): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $edificioId, string $gastoId, array $data): void;

    /** @return array{numero: string} */
    public function register(string $userId, string $edificioId, string $gastoId): array;

    public function cancel(string $userId, string $edificioId, string $gastoId, string $reason): void;
}
