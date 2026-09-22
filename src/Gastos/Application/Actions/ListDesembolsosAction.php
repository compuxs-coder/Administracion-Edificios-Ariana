<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;

final readonly class ListDesembolsosAction
{
    public function __construct(private DesembolsoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
