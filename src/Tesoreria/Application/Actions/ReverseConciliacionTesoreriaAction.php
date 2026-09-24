<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;

final readonly class ReverseConciliacionTesoreriaAction
{
    public function __construct(private MovimientoTesoreriaRepositoryInterface $repository) {}

    public function execute(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        string $conciliacionId,
        string $reason,
    ): void {
        $this->repository->reverse(
            $userId,
            $edificioId,
            $cuentaId,
            $movimientoId,
            $conciliacionId,
            $reason,
        );
    }
}
