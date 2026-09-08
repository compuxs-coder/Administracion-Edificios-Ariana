<?php

namespace Src\Propiedad\Domain\Contracts;

use Src\Propiedad\Domain\Enums\EstadoResidente;

interface ResidenteRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed>|null */
    public function findForUser(string $userId, string $residenteId): ?array;

    /** @return list<array<string, mixed>> */
    public function buildingOptionsForUser(string $userId, bool $forManagement): array;

    /** @return list<array<string, mixed>> */
    public function activeOptionsForUser(string $userId): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createForEdificio(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $userId, string $residenteId, array $data): array;

    public function changeStatus(string $userId, string $residenteId, EstadoResidente $estado): void;
}
