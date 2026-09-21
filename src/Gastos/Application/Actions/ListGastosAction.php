<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;

final readonly class ListGastosAction
{
    public function __construct(private GastoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
