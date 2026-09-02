<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;

final readonly class UpdateResidenteAction
{
    public function __construct(private ResidenteRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $userId, string $residenteId, array $data): array
    {
        return $this->repository->update($userId, $residenteId, $data);
    }
}
