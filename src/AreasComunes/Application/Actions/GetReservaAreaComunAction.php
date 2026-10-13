<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class GetReservaAreaComunAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $reservationId): array
    {
        return $this->repository->getReservation($userId, $buildingId, $reservationId);
    }
}
