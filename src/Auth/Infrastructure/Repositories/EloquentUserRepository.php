<?php

namespace Src\Auth\Infrastructure\Repositories;

use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Domain\Entities\User;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Illuminate\Support\Collection;
use DateTimeImmutable;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function findAll(): Collection
    {
        return UserEloquentModel::all()->map(fn($model) => $this->toDomain($model));
    }

    public function findById(string $id): ?User
    {
        $user = UserEloquentModel::find($id);
        return $user ? $this->toDomain($user) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $user = UserEloquentModel::where('email', $email)->first();
        return $user ? $this->toDomain($user) : null;
    }

    public function save(User $user): User
    {
        $eloquentUser = UserEloquentModel::create([
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'password' => $user->getPassword(),
        ]);

        return $this->toDomain($eloquentUser);
    }

    public function update(string $id, array $data): ?User
    {
        $user = UserEloquentModel::find($id);
        if (!$user) {
            return null;
        }

        $user->update($data);
        return $this->toDomain($user->refresh());
    }

    public function delete(string $id): bool
    {
        $user = UserEloquentModel::find($id);
        if (!$user) {
            return false;
        }

        return (bool)$user->delete();
    }

    public function exists(string $id): bool
    {
        return UserEloquentModel::where('id', $id)->exists();
    }

    public function existsByEmail(string $email): bool
    {
        return UserEloquentModel::where('email', $email)->exists();
    }

    private function toDomain(UserEloquentModel $model): User
    {
        return new User(
            name: $model->name,
            email: $model->email,
            password: $model->password,
            id: $model->id,
            emailVerifiedAt: $model->email_verified_at ? new DateTimeImmutable($model->email_verified_at->toDateTimeString()) : null,
            createdAt: $model->created_at ? new DateTimeImmutable($model->created_at->toDateTimeString()) : null,
            updatedAt: $model->updated_at ? new DateTimeImmutable($model->updated_at->toDateTimeString()) : null
        );
    }
}
