<?php

namespace Src\AreasComunes\Application\Actions;

use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;

final readonly class ListEspaciosComunesAction
{
    public function __construct(private AreaComunRepositoryInterface $repository) {}

    public function execute(string $userId, array $filters): array
    {
        return $this->repository->paginateSpacesForUser($userId, $filters);
    }
}
