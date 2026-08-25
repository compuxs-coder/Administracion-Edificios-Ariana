<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoEstructura;

final readonly class ChangeDepartamentoStatusAction
{
    public function __construct(private DepartamentoRepositoryInterface $repository) {}

    public function execute(
        string $edificioId,
        string $departamentoId,
        EstadoEstructura $estado,
    ): void {
        $this->repository->changeStatus($edificioId, $departamentoId, $estado);
    }
}
