<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;

final readonly class GetProveedorAction
{
    public function __construct(private ProveedorRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $proveedorId): array
    {
        return $this->repository->get($userId, $edificioId, $proveedorId);
    }
}
