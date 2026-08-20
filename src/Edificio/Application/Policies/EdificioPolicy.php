<?php

namespace Src\Edificio\Application\Policies;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class EdificioPolicy
{
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
        return $this->isAssigned($user, $edificio);
    }

    public function update(UserEloquentModel $user, EdificioEloquentModel $edificio): bool
    {
        return $this->isAssigned($user, $edificio);
    }

    public function changeStatus(UserEloquentModel $user, EdificioEloquentModel $edificio): bool
    {
        return $this->isAssigned($user, $edificio);
    }

    private function isAssigned(
        UserEloquentModel $user,
        EdificioEloquentModel $edificio,
    ): bool {
        return $edificio->usuarios()->whereKey($user->getKey())->exists();
    }
}
