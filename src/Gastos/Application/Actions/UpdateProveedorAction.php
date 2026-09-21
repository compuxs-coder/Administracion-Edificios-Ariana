<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;

final readonly class UpdateProveedorAction
{
    public function __construct(private ProveedorRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $proveedorId, array $data): void
    {
        $this->repository->update($userId, $edificioId, $proveedorId, $data);
    }
}
