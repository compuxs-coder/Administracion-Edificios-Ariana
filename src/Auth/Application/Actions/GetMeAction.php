<?php

namespace Src\Auth\Application\Actions;

use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Domain\Entities\User;

class GetMeAction
{
    public function __construct(
        private UserRepositoryInterface $repository
    ) {}

    public function execute(string $userId): ?User
    {
        return $this->repository->findById($userId);
    }
}
