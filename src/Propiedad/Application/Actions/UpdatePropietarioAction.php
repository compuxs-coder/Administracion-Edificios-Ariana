<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;

final readonly class UpdatePropietarioAction
{
    public function __construct(private PropietarioRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $userId, string $propietarioId, array $data): array
    {
        return $this->repository->update($userId, $propietarioId, $data);
    }
}
