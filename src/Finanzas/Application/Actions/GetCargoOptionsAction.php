<?php

namespace Src\Finanzas\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class GetCargoOptionsAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        return $this->cargos->options($userId, $permission);
    }
}
