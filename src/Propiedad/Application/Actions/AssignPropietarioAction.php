<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\TitularidadRepositoryInterface;

final readonly class AssignPropietarioAction
{
    public function __construct(private TitularidadRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(
        string $userId,
        string $edificioId,
        string $departamentoId,
        array $data,
    ): void {
        $this->repository->assign($userId, $edificioId, $departamentoId, $data);
    }
}
