<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoProveedor;

final readonly class ChangeProveedorStatusAction
{
    public function __construct(private ProveedorRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $proveedorId, EstadoProveedor $status): void
    {
        $this->repository->changeStatus($userId, $edificioId, $proveedorId, $status);
    }
}
