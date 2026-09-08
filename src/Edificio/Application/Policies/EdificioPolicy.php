<?php

namespace Src\Edificio\Application\Policies;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class EdificioPolicy
{
    public function __construct(private readonly AccesoEdificioService $access) {}

    public function viewAny(UserEloquentModel $user): bool
    {
        return true;
    }

    public function create(UserEloquentModel $user): bool
    {
        return true;
    }

    public function view(UserEloquentModel $user, EdificioEloquentModel $edificio): bool
    {
        return $this->access->allows((string) $user->getKey(), $edificio->id, PermisoEdificio::EDIFICIO_VER);
    }

    public function update(UserEloquentModel $user, EdificioEloquentModel $edificio): bool
    {
        return $this->access->allows((string) $user->getKey(), $edificio->id, PermisoEdificio::EDIFICIO_EDITAR);
    }

    public function changeStatus(UserEloquentModel $user, EdificioEloquentModel $edificio): bool
    {
        return $this->access->allows((string) $user->getKey(), $edificio->id, PermisoEdificio::EDIFICIO_CAMBIAR_ESTADO);
    }

    public function access(
        UserEloquentModel $user,
        EdificioEloquentModel $edificio,
        PermisoEdificio|string $permission,
    ): bool {
        $permission = is_string($permission) ? PermisoEdificio::tryFrom($permission) : $permission;

        return $permission !== null
            && $this->access->allows((string) $user->getKey(), $edificio->id, $permission);
    }
}
