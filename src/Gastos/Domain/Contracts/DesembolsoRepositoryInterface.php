<?php

namespace Src\Gastos\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface DesembolsoRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $desembolsoId): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::DESEMBOLSOS_VER): array;

    /** @return array<string, mixed> */
    public function preview(string $userId, string $edificioId, string $proveedorId, string $amount): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    public function cancel(string $userId, string $edificioId, string $desembolsoId, string $reason): void;
}
