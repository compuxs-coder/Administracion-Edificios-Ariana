<?php

namespace Src\Tesoreria\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;

interface CuentaTesoreriaRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $cuentaId): array;

    /** @return array<string, mixed> */
    public function getForManagement(string $userId, string $edificioId, string $cuentaId): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $edificioId, string $cuentaId, array $data): void;

    public function changeStatus(
        string $userId,
        string $edificioId,
        string $cuentaId,
        EstadoCuentaTesoreria $estado,
    ): void;
}
