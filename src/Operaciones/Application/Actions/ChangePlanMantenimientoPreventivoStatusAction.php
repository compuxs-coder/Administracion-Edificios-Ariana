<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class ChangePlanMantenimientoPreventivoStatusAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $planId, string $state, ?string $nextDate): void
    {
        $this->repository->changeStatus($userId, $edificioId, $planId, $state, $nextDate);
    }
}
