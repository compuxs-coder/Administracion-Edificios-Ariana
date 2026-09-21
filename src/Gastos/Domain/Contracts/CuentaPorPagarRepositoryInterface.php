<?php

namespace Src\Gastos\Domain\Contracts;

interface CuentaPorPagarRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $cuentaId): array;
}
