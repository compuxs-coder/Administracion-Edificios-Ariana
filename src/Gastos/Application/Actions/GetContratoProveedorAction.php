<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;

final readonly class GetContratoProveedorAction
{
    public function __construct(private ContratoProveedorRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $contratoId): array
    {
        return $this->repository->get($userId, $edificioId, $contratoId);
    }
}
