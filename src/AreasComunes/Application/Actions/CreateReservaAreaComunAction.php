<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class CreateReservaAreaComunAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $spaceId, array $data): array
    {
        return $this->repository->createReservation($userId, $buildingId, $spaceId, $data);
    }
}
