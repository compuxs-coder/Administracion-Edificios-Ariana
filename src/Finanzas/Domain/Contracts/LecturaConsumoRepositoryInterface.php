<?php

namespace Src\Finanzas\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface LecturaConsumoRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function list(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;
}
