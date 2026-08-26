<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;

final readonly class ListPropietariosAction
{
    public function __construct(private PropietarioRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
