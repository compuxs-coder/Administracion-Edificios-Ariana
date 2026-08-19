<?php

namespace Src\Auth\Application\Actions;

use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Throwable;

class LoginAction
{
    public function __construct(
        private UserRepositoryInterface $repository
    ) {}

    public function execute(array $credentials, bool $issueToken = true, bool $remember = false): ?array
    {
        if (!Auth::attempt($credentials, $remember)) {
            return null;
        }

        try {
            $user = $this->repository->findByEmail($credentials['email']);
            if (!$user) {
                Auth::guard('web')->logout();

                return null;
            }

            $result = [
                'user' => $user,
            ];

            if ($issueToken) {
                $eloquentUser = UserEloquentModel::findOrFail($user->getId());
                $result['token'] = $eloquentUser->createToken('auth_token')->plainTextToken;
            }

            return $result;
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();

            throw $exception;
        }
    }
}
