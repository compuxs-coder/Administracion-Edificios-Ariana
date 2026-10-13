<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class UpdateEspacioComunAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $spaceId, array $data): void
    {
        $this->repository->updateSpace($userId, $buildingId, $spaceId, $data);
    }
}
