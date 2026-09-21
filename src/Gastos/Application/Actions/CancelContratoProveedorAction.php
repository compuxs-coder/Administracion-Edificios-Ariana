<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;

final readonly class CancelContratoProveedorAction
{
    public function __construct(private ContratoProveedorRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $contratoId, string $reason): void
    {
        $this->repository->cancel($userId, $edificioId, $contratoId, $reason);
    }
}
