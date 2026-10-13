<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class ListReservasAreasComunesAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateReservationsForUser($userId, $filters);
    }
}
