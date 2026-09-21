<?php

namespace Src\Propiedad\Application\Policies;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Application\Services\TerceroIdentityAuthorizationService;

final class ResidentePolicy
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly TerceroIdentityAuthorizationService $identityAccess,
    ) {}

    public function viewAny(UserEloquentModel $user): bool
    {
        return true;
    }

    public function create(UserEloquentModel $user): bool
    {
        return $this->access->allowsAny((string) $user->getKey(), PermisoEdificio::PROPIEDAD_GESTIONAR);
    }

    public function view(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        return array_intersect(
            $residente->edificios()->get()->modelKeys(),
            $this->access->buildingIds((string) $user->getKey(), PermisoEdificio::PROPIEDAD_VER),
        ) !== [];
    }

    public function update(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        $tercero = $residente->tercero()->first();

        return $tercero !== null
            && $this->identityAccess->allows((string) $user->getKey(), $tercero);
    }

    public function changeStatus(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        return $this->canManageGlobally($user, $residente);
    }

    private function canManageGlobally(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        $buildingIds = $residente->edificios()->get()->modelKeys();
        $propietario = $residente->tercero?->propietario()->first();
        if ($propietario !== null) {
            $buildingIds = array_values(array_unique([...$buildingIds, ...$propietario->edificios()->get()->modelKeys()]));
        }

        return $this->access->allowsEvery(
            (string) $user->getKey(),
            $buildingIds,
            PermisoEdificio::PROPIEDAD_GESTIONAR,
        );
    }
}
