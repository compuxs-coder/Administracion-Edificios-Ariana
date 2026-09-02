<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\OcupacionRepositoryInterface;

final readonly class GetDepartamentoOcupacionAction
{
    public function __construct(private OcupacionRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $departamentoId): array
    {
        return $this->repository->getForDepartamento($userId, $edificioId, $departamentoId);
    }
}
