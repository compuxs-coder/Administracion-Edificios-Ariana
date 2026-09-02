<?php

namespace Src\Propiedad\Application\Policies;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;

final class ResidentePolicy
{
    public function viewAny(UserEloquentModel $user): bool
    {
        return true;
    }

    public function create(UserEloquentModel $user): bool
    {
        return true;
    }

    public function view(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        return $residente->edificios()
            ->whereHas('usuarios', static fn ($query) => $query->whereKey($user->getKey()))
            ->exists();
    }

    public function update(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        return $this->canManageGlobally($user, $residente);
    }

    public function changeStatus(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        return $this->canManageGlobally($user, $residente);
    }

    private function canManageGlobally(UserEloquentModel $user, ResidenteEloquentModel $residente): bool
    {
        if (! $this->view($user, $residente)
            || $residente->edificios()
                ->whereDoesntHave('usuarios', static fn ($query) => $query->whereKey($user->getKey()))
                ->exists()) {
            return false;
        }

        $propietario = $residente->tercero?->propietario()->first();

        return $propietario === null
            || ! $propietario->edificios()
                ->whereDoesntHave('usuarios', static fn ($query) => $query->whereKey($user->getKey()))
                ->exists();
    }
}
