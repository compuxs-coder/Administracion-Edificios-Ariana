<?php

namespace Src\Finanzas\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;

final readonly class GetConceptoCobroOptionsAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    /** @return list<array{id: string, nombre: string}> */
    public function buildings(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        return $this->conceptos->buildingOptions($userId, $permission);
    }
}
