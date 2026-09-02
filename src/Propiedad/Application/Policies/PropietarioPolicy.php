<?php

namespace Src\Propiedad\Application\Policies;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class PropietarioPolicy
{
    public function viewAny(UserEloquentModel $user): bool
    {
        return true;
    }

    public function create(UserEloquentModel $user): bool
    {
        return true;
    }

    public function view(UserEloquentModel $user, PropietarioEloquentModel $propietario): bool
    {
        return $this->isVisible($user, $propietario);
    }

    public function update(UserEloquentModel $user, PropietarioEloquentModel $propietario): bool
    {
        return $this->canManageGlobally($user, $propietario);
    }

    public function changeStatus(UserEloquentModel $user, PropietarioEloquentModel $propietario): bool
    {
        return $this->canManageGlobally($user, $propietario);
    }

    private function isVisible(
        UserEloquentModel $user,
        PropietarioEloquentModel $propietario,
    ): bool {
        return $propietario->edificios()
            ->whereHas('usuarios', static fn ($query) => $query->whereKey($user->getKey()))
            ->exists();
    }

    private function canManageGlobally(
        UserEloquentModel $user,
        PropietarioEloquentModel $propietario,
    ): bool {
        $canManageOwner = $this->isVisible($user, $propietario)
            && ! $propietario->edificios()
                ->whereDoesntHave('usuarios', static fn ($query) => $query->whereKey($user->getKey()))
                ->exists();

        if (! $canManageOwner) {
            return false;
        }

        $residente = $propietario->tercero?->residente()->first();

        return $residente === null
            || ! $residente->edificios()
                ->whereDoesntHave('usuarios', static fn ($query) => $query->whereKey($user->getKey()))
                ->exists();
    }
}
