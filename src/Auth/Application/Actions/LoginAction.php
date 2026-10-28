<?php

namespace Src\Auth\Application\Actions;

use Illuminate\Auth\Events\Login;
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
        $guard = Auth::guard('web');
        $authenticated = $issueToken
            ? $guard->once($credentials)
            : $guard->attempt($credentials, $remember);

        if (! $authenticated) {
            return null;
        }

        try {
            $user = $this->repository->findByEmail($credentials['email']);
            if (!$user) {
                if (! $issueToken) {
                    $guard->logout();
                }

                return null;
            }

            $result = [
                'user' => $user,
            ];

            if ($issueToken) {
                $eloquentUser = UserEloquentModel::findOrFail($user->getId());
                $result['token'] = $eloquentUser->createApiToken()->plainTextToken;
                event(new Login('web', $eloquentUser, false));
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $issueToken) {
                $guard->logout();
            }

            throw $exception;
        } finally {
            if ($issueToken) {
                $guard->forgetUser();
            }
        }
    }
}
