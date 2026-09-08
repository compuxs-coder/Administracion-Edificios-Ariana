<?php

namespace Src\Edificio\Application\Services;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final readonly class AccesoEdificioService
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function allows(string $userId, string $edificioId, PermisoEdificio $permission): bool
    {
        return $this->repository->hasPermission($userId, $edificioId, $permission);
    }

    public function allowsAny(string $userId, PermisoEdificio $permission): bool
    {
        return $this->buildingIds($userId, $permission) !== [];
    }

    /** @return list<string> */
    public function buildingIds(string $userId, PermisoEdificio $permission): array
    {
        return $this->repository->buildingIds($userId, $permission);
    }

    /** @param iterable<string> $buildingIds */
    public function allowsEvery(string $userId, iterable $buildingIds, PermisoEdificio $permission): bool
    {
        $required = array_values(array_unique(is_array($buildingIds) ? $buildingIds : iterator_to_array($buildingIds)));

        return $required !== [] && array_diff($required, $this->buildingIds($userId, $permission)) === [];
    }

    /** @return array<string, list<string>> */
    public function permissionMap(string $userId): array
    {
        return $this->repository->permissionMap($userId);
    }
}
