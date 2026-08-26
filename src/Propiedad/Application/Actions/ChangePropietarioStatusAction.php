<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoPropietario;

final readonly class ChangePropietarioStatusAction
{
    public function __construct(private PropietarioRepositoryInterface $repository) {}

    public function execute(
        string $userId,
        string $propietarioId,
        EstadoPropietario $estado,
    ): void {
        $this->repository->changeStatus($userId, $propietarioId, $estado);
    }
}
