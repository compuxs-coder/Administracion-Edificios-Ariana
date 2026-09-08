<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;

final readonly class GetResidenteOptionsAction
{
    public function __construct(private ResidenteRepositoryInterface $repository) {}

    /** @return list<array<string, mixed>> */
    public function buildings(string $userId, bool $forManagement = false): array
    {
        return $this->repository->buildingOptionsForUser($userId, $forManagement);
    }

    /** @return list<array<string, mixed>> */
    public function activeResidents(string $userId): array
    {
        return $this->repository->activeOptionsForUser($userId);
    }
}
