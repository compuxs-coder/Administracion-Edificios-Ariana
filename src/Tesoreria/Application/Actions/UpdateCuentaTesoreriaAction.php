<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;

final readonly class UpdateCuentaTesoreriaAction
{
    public function __construct(private CuentaTesoreriaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $cuentaId, array $data): void
    {
        $this->repository->update($userId, $edificioId, $cuentaId, $data);
    }
}
