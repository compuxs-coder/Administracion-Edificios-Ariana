<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class OmitOccurrenceMantenimientoPreventivoAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $planId, string $occurrenceId, string $reason): void
    {
        $this->repository->omit($userId, $edificioId, $planId, $occurrenceId, $reason);
    }
}
