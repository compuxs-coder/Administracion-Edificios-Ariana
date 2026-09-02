<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoResidente;

final readonly class ChangeResidenteStatusAction
{
    public function __construct(private ResidenteRepositoryInterface $repository) {}

    public function execute(string $userId, string $residenteId, EstadoResidente $estado): void
    {
        $this->repository->changeStatus($userId, $residenteId, $estado);
    }
}
