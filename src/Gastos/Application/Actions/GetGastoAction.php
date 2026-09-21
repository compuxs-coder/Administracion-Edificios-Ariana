<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;

final readonly class GetGastoAction
{
    public function __construct(private GastoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $gastoId): array
    {
        return $this->repository->get($userId, $edificioId, $gastoId);
    }
}
