<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class GetPlanMantenimientoPreventivoAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $planId): array
    {
        return $this->repository->get($userId, $edificioId, $planId);
    }
}
