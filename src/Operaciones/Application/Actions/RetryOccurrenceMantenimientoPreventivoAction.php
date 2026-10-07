<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class RetryOccurrenceMantenimientoPreventivoAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $planId, string $occurrenceId): void
    {
        $this->repository->retry($userId, $edificioId, $planId, $occurrenceId);
    }
}
