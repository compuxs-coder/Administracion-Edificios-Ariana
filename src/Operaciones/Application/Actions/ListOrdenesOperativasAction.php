<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class ListOrdenesOperativasAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
