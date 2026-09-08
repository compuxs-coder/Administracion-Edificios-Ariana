<?php

namespace Src\Propiedad\Application\Policies;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class PropietarioPolicy
{
    public function __construct(private readonly AccesoEdificioService $access) {}

    public function viewAny(UserEloquentModel $user): bool
    {
        return true;
    }

    public function create(UserEloquentModel $user): bool
    {
        return $this->access->allowsAny((string) $user->getKey(), PermisoEdificio::PROPIEDAD_GESTIONAR);
    }

    public function view(UserEloquentModel $user, PropietarioEloquentModel $propietario): bool
    {
        $buildingIds = $propietario->edificios()->get()->modelKeys();

        return array_intersect(
            $buildingIds,
            $this->access->buildingIds((string) $user->getKey(), PermisoEdificio::PROPIEDAD_VER),
        ) !== [];
    }

    public function update(UserEloquentModel $user, PropietarioEloquentModel $propietario): bool
    {
        return $this->canManageGlobally($user, $propietario);
    }

    public function changeStatus(UserEloquentModel $user, PropietarioEloquentModel $propietario): bool
    {
        return $this->canManageGlobally($user, $propietario);
    }

    private function canManageGlobally(
        UserEloquentModel $user,
        PropietarioEloquentModel $propietario,
    ): bool {
        $buildingIds = $propietario->edificios()->get()->modelKeys();
        $residente = $propietario->tercero?->residente()->first();
        if ($residente !== null) {
            $buildingIds = array_values(array_unique([...$buildingIds, ...$residente->edificios()->get()->modelKeys()]));
        }

        return $this->access->allowsEvery(
            (string) $user->getKey(),
            $buildingIds,
            PermisoEdificio::PROPIEDAD_GESTIONAR,
        );
    }
}
