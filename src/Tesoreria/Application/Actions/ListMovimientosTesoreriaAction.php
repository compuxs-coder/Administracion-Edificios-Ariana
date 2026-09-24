<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;

final readonly class ListMovimientosTesoreriaAction
{
    public function __construct(private MovimientoTesoreriaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateForUser($userId, $filters);
    }
}
