<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;

final readonly class ListContratosProveedorAction
{
    public function __construct(private ContratoProveedorRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
