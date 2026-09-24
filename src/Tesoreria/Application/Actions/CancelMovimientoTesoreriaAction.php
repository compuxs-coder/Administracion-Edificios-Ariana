<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;

final readonly class CancelMovimientoTesoreriaAction
{
    public function __construct(private MovimientoTesoreriaRepositoryInterface $repository) {}

    public function execute(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        string $reason,
    ): void {
        $this->repository->cancel($userId, $edificioId, $cuentaId, $movimientoId, $reason);
    }
}
