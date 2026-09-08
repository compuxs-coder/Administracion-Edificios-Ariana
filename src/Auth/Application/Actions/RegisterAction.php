<?php

namespace Src\Auth\Application\Actions;

use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Domain\Entities\User;
use Illuminate\Support\Facades\Hash;
use Src\Auth\Infrastructure\Models\UserEloquentModel;

class RegisterAction
{
    public function __construct(
        private UserRepositoryInterface $repository
    ) {}

    public function execute(array $data, bool $issueToken = true): array
    {
        $user = new User(
            name: $data['name'],
            email: mb_strtolower(trim($data['email'])),
            password: Hash::make($data['password'])
        );

        $savedUser = $this->repository->save($user);

        $result = [
            'user' => $savedUser,
        ];

        if ($issueToken) {
            $eloquentUser = UserEloquentModel::findOrFail($savedUser->getId());
            $result['token'] = $eloquentUser->createToken('auth_token')->plainTextToken;
        }

        return $result;
    }
}
