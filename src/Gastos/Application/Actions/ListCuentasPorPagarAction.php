<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\CuentaPorPagarRepositoryInterface;

final readonly class ListCuentasPorPagarAction
{
    public function __construct(private CuentaPorPagarRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
