<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class GenerateCargosAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $periodo, ?string $conceptoId = null): array
    {
        return $this->cargos->generate($userId, $edificioId, $periodo, $conceptoId);
    }
}
