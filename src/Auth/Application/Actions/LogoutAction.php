<?php

namespace Src\Auth\Application\Actions;

use Src\Auth\Domain\Contracts\UserRepositoryInterface;

use Src\Auth\Infrastructure\Models\UserEloquentModel;

class LogoutAction
{
    public function execute(string $userId): bool
    {
        // Get the Eloquent model to delete token
        $user = UserEloquentModel::find($userId);
        if (!$user) {
            return false;
        }

        $user->currentAccessToken()?->delete();
        return true;
    }
}
