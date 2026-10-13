<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final readonly class GetAreasComunesOptionsAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function spaces(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array
    {
        return $this->repository->spaceOptions($userId, $permission, $buildingId);
    }

    public function reservations(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array
    {
        return $this->repository->reservationOptions($userId, $permission, $buildingId);
    }
}
