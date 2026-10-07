<?php

namespace Src\Operaciones\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;

final readonly class GetMantenimientoPreventivoOptionsAction
{
    public function __construct(private MantenimientoPreventivoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission, ?string $edificioId = null): array
    {
        return $this->repository->options($userId, $permission, $edificioId);
    }

    /** @return array{edificios: list<array<string, mixed>>} */
    public function forIndex(string $userId): array
    {
        return $this->repository->indexOptions($userId);
    }
}
