<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;

final readonly class ListResidentesAction
{
    public function __construct(private ResidenteRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
