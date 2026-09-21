<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;

final readonly class RegisterContratoProveedorAction
{
    public function __construct(private ContratoProveedorRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $contratoId): void
    {
        $this->repository->register($userId, $edificioId, $contratoId);
    }
}
