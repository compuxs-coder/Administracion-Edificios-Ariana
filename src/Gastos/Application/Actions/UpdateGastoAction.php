<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;

final readonly class UpdateGastoAction
{
    public function __construct(private GastoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $gastoId, array $data): void
    {
        $this->repository->update($userId, $edificioId, $gastoId, $data);
    }
}
