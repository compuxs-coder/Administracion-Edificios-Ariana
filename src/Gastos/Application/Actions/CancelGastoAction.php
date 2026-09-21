<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;

final readonly class CancelGastoAction
{
    public function __construct(private GastoRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $gastoId, string $reason): void
    {
        $this->repository->cancel($userId, $edificioId, $gastoId, $reason);
    }
}
