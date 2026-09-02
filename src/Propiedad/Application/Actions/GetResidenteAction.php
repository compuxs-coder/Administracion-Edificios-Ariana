<?php

namespace Src\Propiedad\Application\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;

final readonly class GetResidenteAction
{
    public function __construct(private ResidenteRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $residenteId): array
    {
        return $this->repository->findForUser($userId, $residenteId)
            ?? throw (new ModelNotFoundException())->setModel('Residente', [$residenteId]);
    }
}
