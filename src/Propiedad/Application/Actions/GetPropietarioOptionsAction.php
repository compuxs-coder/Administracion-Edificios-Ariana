<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;

final readonly class GetPropietarioOptionsAction
{
    public function __construct(private PropietarioRepositoryInterface $repository) {}

    /** @return list<array<string, mixed>> */
    public function buildings(string $userId, bool $forManagement = false): array
    {
        return $this->repository->buildingOptionsForUser($userId, $forManagement);
    }

    /** @return list<array<string, mixed>> */
    public function activeOwners(string $userId): array
    {
        return $this->repository->activeOptionsForUser($userId);
    }
}
