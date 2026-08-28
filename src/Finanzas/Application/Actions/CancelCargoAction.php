<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class CancelCargoAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    public function execute(string $userId, string $edificioId, string $cargoId, string $motivo): void
    {
        $this->cargos->cancel($userId, $edificioId, $cargoId, $motivo);
    }
}
