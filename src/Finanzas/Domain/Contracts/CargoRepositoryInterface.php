<?php

namespace Src\Finanzas\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface CargoRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function list(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $cargoId): array;

    /** @return array<string, mixed> */
    public function preview(?string $userId, string $edificioId, string $periodo, ?string $conceptoId = null): array;

    /** @return array<string, mixed> */
    public function generate(?string $userId, string $edificioId, string $periodo, ?string $conceptoId = null): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function createManual(string $userId, string $edificioId, array $data): array;

    public function cancel(string $userId, string $edificioId, string $cargoId, string $motivo): void;

    /** @return list<array<string, mixed>> */
    public function automatic(string $periodo, ?string $edificioId = null, ?string $conceptoId = null, bool $dryRun = false): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array;
}
