<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class UpdatePlanMantenimientoPreventivoAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $planId, array $data): void
    {
        $this->repository->update($userId, $edificioId, $planId, $data);
    }
}
