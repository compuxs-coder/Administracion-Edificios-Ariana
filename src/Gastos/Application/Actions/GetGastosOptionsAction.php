<?php

namespace Src\Gastos\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;

final readonly class GetGastosOptionsAction
{
    public function __construct(private ProveedorRepositoryInterface $repository) {}

    /** @return array{edificios: list<array<string, mixed>>, proveedores: list<array<string, mixed>>, contratos: list<array<string, mixed>>} */
    public function execute(string $userId, PermisoEdificio $permission = PermisoEdificio::GASTOS_VER): array
    {
        return $this->repository->options($userId, $permission);
    }
}
