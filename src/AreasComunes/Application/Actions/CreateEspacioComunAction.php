<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class CreateEspacioComunAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, array $data): array
    {
        return $this->repository->createSpace($userId, $buildingId, $data);
    }
}
