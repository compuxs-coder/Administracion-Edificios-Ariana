<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class GetCargoAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $cargoId): array
    {
        return $this->cargos->get($userId, $edificioId, $cargoId);
    }
}
