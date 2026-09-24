<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;

final readonly class ChangeCuentaTesoreriaStatusAction
{
    public function __construct(private CuentaTesoreriaRepositoryInterface $repository) {}

    public function execute(
        string $userId,
        string $edificioId,
        string $cuentaId,
        EstadoCuentaTesoreria $estado,
    ): void {
        $this->repository->changeStatus($userId, $edificioId, $cuentaId, $estado);
    }
}
