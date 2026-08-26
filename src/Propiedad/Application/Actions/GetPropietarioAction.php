<?php

namespace Src\Propiedad\Application\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;

final readonly class GetPropietarioAction
{
    public function __construct(private PropietarioRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $propietarioId): array
    {
        return $this->repository->findForUser($userId, $propietarioId)
            ?? throw (new ModelNotFoundException())->setModel('Propietario', [$propietarioId]);
    }
}
