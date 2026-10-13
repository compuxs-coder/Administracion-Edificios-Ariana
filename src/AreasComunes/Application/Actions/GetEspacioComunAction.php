<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class GetEspacioComunAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $spaceId): array
    {
        return $this->repository->getSpace($userId, $buildingId, $spaceId);
    }
}
