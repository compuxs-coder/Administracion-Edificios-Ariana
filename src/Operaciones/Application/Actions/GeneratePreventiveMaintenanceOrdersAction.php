<?php

namespace Src\Operaciones\Application\Actions;

use Carbon\CarbonImmutable;
use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class GeneratePreventiveMaintenanceOrdersAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    /** @return array{procesadas: int, generadas: int, bloqueadas: int, omitidas: int, reutilizadas: int, limiteAlcanzado: bool, dryRun: bool} */
    public function execute(CarbonImmutable $date, ?string $edificioId, int $limit, bool $dryRun = false): array
    {
        return $this->repository->generateDue($date, $edificioId, $limit, $dryRun);
    }
}
