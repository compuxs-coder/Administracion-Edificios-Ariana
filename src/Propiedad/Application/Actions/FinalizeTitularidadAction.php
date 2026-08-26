<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\TitularidadRepositoryInterface;

final readonly class FinalizeTitularidadAction
{
    public function __construct(private TitularidadRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(
        string $userId,
        string $edificioId,
        string $departamentoId,
        string $titularidadId,
        array $data,
    ): void {
        $this->repository->finalize($userId, $edificioId, $departamentoId, $titularidadId, $data);
    }
}
