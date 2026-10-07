<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class ListPlanesMantenimientoPreventivoAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
