<?php

namespace Src\Propiedad\Application\Services;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;

final readonly class TerceroIdentityAuthorizationService
{
    public function __construct(private AccesoEdificioRepositoryInterface $access) {}

    public function allows(string $userId, TerceroEloquentModel $tercero): bool
    {
        $propertyBuildingIds = [];
        foreach ([$tercero->propietario()->first(), $tercero->residente()->first()] as $profile) {
            if ($profile !== null) {
                $propertyBuildingIds = [...$propertyBuildingIds, ...$profile->edificios()->get()->modelKeys()];
            }
        }

        $supplierBuildingIds = $tercero->proveedor()->first()?->edificios()->get()->modelKeys() ?? [];
        $propertyBuildingIds = array_values(array_unique($propertyBuildingIds));
        $supplierBuildingIds = array_values(array_unique($supplierBuildingIds));

        if ($propertyBuildingIds === [] && $supplierBuildingIds === []) {
            return false;
        }

        return array_diff(
            $propertyBuildingIds,
            $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR),
        ) === [] && array_diff(
            $supplierBuildingIds,
            $this->access->buildingIds($userId, PermisoEdificio::GASTOS_GESTIONAR),
        ) === [];
    }
}
