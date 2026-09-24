<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;

final readonly class GetConciliacionTesoreriaOptionsAction
{
    public function __construct(private MovimientoTesoreriaRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $cuentaId, string $movimientoId): array
    {
        return $this->repository->reconciliationOptions($userId, $edificioId, $cuentaId, $movimientoId);
    }
}
