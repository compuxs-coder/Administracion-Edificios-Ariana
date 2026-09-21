<?php

namespace Src\Gastos\Domain\Contracts;

interface ContratoProveedorRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $contratoId): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $edificioId, string $contratoId, array $data): void;

    public function register(string $userId, string $edificioId, string $contratoId): void;

    public function cancel(string $userId, string $edificioId, string $contratoId, string $reason): void;
}
