<?php

namespace Src\Propiedad\Domain\Contracts;

use Src\Propiedad\Domain\Enums\EstadoPropietario;

interface PropietarioRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed>|null */
    public function findForUser(string $userId, string $propietarioId): ?array;

    /** @return list<array<string, mixed>> */
    public function buildingOptionsForUser(string $userId): array;

    /** @return list<array<string, mixed>> */
    public function activeOptionsForUser(string $userId): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createForEdificio(string $edificioId, array $data): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $userId, string $propietarioId, array $data): array;

    public function changeStatus(
        string $userId,
        string $propietarioId,
        EstadoPropietario $estado,
    ): void;
}
