<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;

final readonly class CreateProveedorAction
{
    public function __construct(private ProveedorRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $data);
    }
}
