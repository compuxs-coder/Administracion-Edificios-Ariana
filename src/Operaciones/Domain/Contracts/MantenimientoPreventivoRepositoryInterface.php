<?php

namespace Src\Operaciones\Domain\Contracts;

use Carbon\CarbonImmutable;
use Src\Edificio\Domain\Enums\PermisoEdificio;

interface MantenimientoPreventivoRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $planId): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission, ?string $edificioId = null): array;

    /** @return array{edificios: list<array<string, mixed>>} */
    public function indexOptions(string $userId): array;

    /** @param array<string, mixed> $data @return array{id: string, codigo: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $edificioId, string $planId, array $data): void;

    public function changeStatus(string $userId, string $edificioId, string $planId, string $state, ?string $nextDate): void;

    public function retry(string $userId, string $edificioId, string $planId, string $occurrenceId): void;

    public function omit(string $userId, string $edificioId, string $planId, string $occurrenceId, string $reason): void;

    /** @return array{procesadas: int, generadas: int, bloqueadas: int, omitidas: int, reutilizadas: int, limiteAlcanzado: bool, dryRun: bool} */
    public function generateDue(CarbonImmutable $date, ?string $edificioId, int $limit, bool $dryRun = false): array;
}
