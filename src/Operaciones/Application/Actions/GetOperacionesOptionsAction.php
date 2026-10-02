<?php

namespace Src\Operaciones\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class GetOperacionesOptionsAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(
        string $userId,
        PermisoEdificio $permission = PermisoEdificio::OPERACIONES_VER,
        ?string $edificioId = null,
    ): array
    {
        return $this->repository->options($userId, $permission, $edificioId);
    }

    /** @return array{edificios: list<array<string, mixed>>} */
    public function forIndex(string $userId): array
    {
        return $this->repository->indexOptions($userId);
    }

    /** @return array{miembros: list<array<string, mixed>>, proveedores: list<array<string, mixed>>} */
    public function forAssignment(string $userId, string $edificioId): array
    {
        return $this->repository->assignmentOptions($userId, $edificioId);
    }
}
