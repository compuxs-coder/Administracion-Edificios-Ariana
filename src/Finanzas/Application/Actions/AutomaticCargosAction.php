<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class AutomaticCargosAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    /** @return list<array<string, mixed>> */
    public function execute(string $periodo, ?string $edificioId = null, ?string $conceptoId = null, bool $dryRun = false): array
    {
        return $this->cargos->automatic($periodo, $edificioId, $conceptoId, $dryRun);
    }
}
