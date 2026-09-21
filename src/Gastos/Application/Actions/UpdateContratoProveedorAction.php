<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;

final readonly class UpdateContratoProveedorAction
{
    public function __construct(private ContratoProveedorRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $contratoId, array $data): void
    {
        $this->repository->update($userId, $edificioId, $contratoId, $data);
    }
}
