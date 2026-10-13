<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class TransitionReservaAreaComunAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $reservationId, string $status, ?string $reason): void
    {
        $this->repository->transitionReservation($userId, $buildingId, $reservationId, $status, $reason);
    }
}
