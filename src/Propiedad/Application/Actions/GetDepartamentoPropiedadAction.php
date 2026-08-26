<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\TitularidadRepositoryInterface;

final readonly class GetDepartamentoPropiedadAction
{
    public function __construct(private TitularidadRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(
        string $userId,
        string $edificioId,
        string $departamentoId,
    ): array {
        return $this->repository->getForDepartamento($userId, $edificioId, $departamentoId);
    }
}
