<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;

final readonly class ListDepartamentosAction
{
    public function __construct(private DepartamentoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
