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

    public function execute(array $data): array
    {
        $user = new User(
            name: $data['name'],
            email: $data['email'],
            password: Hash::make($data['password'])
        );

        $savedUser = $this->repository->save($user);

        $eloquentUser = UserEloquentModel::find($savedUser->getId());
        $token = $eloquentUser->createToken('auth_token')->plainTextToken;

        return [
            'user' => $savedUser,
            'token' => $token
        ];
    }
}
