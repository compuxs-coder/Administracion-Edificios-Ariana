<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;

final readonly class ListCuentasTesoreriaAction
{
    public function __construct(private CuentaTesoreriaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
