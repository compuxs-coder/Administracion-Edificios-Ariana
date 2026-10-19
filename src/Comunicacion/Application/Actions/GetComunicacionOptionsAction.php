<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final readonly class GetComunicacionOptionsAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array
    {
        return $this->repository->options($userId, $permission, $buildingId);
    }
}
