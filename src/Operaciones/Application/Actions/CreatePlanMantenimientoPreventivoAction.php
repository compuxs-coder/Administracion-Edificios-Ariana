<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class CreatePlanMantenimientoPreventivoAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string, codigo: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $data);
    }
}
