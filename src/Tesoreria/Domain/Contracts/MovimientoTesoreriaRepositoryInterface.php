<?php

namespace Src\Tesoreria\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface MovimientoTesoreriaRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $cuentaId, string $movimientoId): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission, bool $onlyActiveAccounts = false): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, string $cuentaId, array $data): array;

    public function cancel(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        string $reason,
    ): void;

    /** @return array<string, mixed> */
    public function reconciliationOptions(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
    ): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function reconcile(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        array $data,
    ): array;

    public function reverse(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        string $conciliacionId,
        string $reason,
    ): void;
}
