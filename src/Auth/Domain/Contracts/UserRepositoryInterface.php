<?php

namespace Src\Auth\Domain\Contracts;

use Src\Auth\Domain\Entities\User;
use Illuminate\Support\Collection;

interface UserRepositoryInterface
{
    public function findAll(): Collection;

    public function findById(string $id): ?User;

    public function findByEmail(string $email): ?User;

    public function save(User $user): User;

    public function update(string $id, array $data): ?User;

    public function delete(string $id): bool;

    public function exists(string $id): bool;

    public function existsByEmail(string $email): bool;
}
