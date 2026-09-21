<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\CuentaPorPagarRepositoryInterface;

final readonly class GetCuentaPorPagarAction
{
    public function __construct(private CuentaPorPagarRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $cuentaId): array
    {
        return $this->repository->get($userId, $edificioId, $cuentaId);
    }
}
