<?php

namespace Src\Finanzas\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Domain\Contracts\LecturaConsumoRepositoryInterface;

final readonly class GetLecturaConsumoOptionsAction
{
    public function __construct(private LecturaConsumoRepositoryInterface $lecturas) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        return $this->lecturas->options($userId, $permission);
    }
}
