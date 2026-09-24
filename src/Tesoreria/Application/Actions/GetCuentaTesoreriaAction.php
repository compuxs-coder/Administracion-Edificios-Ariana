<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;

final readonly class GetCuentaTesoreriaAction
{
    public function __construct(private CuentaTesoreriaRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $cuentaId): array
    {
        return $this->repository->get($userId, $edificioId, $cuentaId);
    }

    /** @return array<string, mixed> */
    public function executeForManagement(string $userId, string $edificioId, string $cuentaId): array
    {
        return $this->repository->getForManagement($userId, $edificioId, $cuentaId);
    }
}
