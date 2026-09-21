<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;

final readonly class RegisterGastoAction
{
    public function __construct(private GastoRepositoryInterface $repository) {}

    /** @return array{numero: string} */
    public function execute(string $userId, string $edificioId, string $gastoId): array
    {
        return $this->repository->register($userId, $edificioId, $gastoId);
    }
}
