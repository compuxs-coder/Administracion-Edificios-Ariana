<?php

namespace Src\Auth\Application\Actions;

use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Src\Auth\Infrastructure\Models\UserEloquentModel;

class LoginAction
{
    public function __construct(
        private UserRepositoryInterface $repository
    ) {}

    public function execute(array $credentials): ?array
    {
        if (!Auth::attempt($credentials)) {
            return null;
        }

        $user = $this->repository->findByEmail($credentials['email']);
        if (!$user) {
            return null;
        }

        // Get the Eloquent model to create token
        $eloquentUser = UserEloquentModel::find($user->getId());
        $token = $eloquentUser->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token
        ];
    }
}
