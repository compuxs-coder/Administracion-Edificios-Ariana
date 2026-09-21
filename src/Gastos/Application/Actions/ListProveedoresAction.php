<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;

final readonly class ListProveedoresAction
{
    public function __construct(private ProveedorRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
