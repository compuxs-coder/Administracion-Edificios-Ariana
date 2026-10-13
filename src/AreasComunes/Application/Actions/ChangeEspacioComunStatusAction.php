<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class ChangeEspacioComunStatusAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $spaceId, string $status): void
    {
        $this->repository->changeSpaceStatus($userId, $buildingId, $spaceId, $status);
    }
}
