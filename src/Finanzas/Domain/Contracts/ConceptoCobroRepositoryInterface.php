<?php

namespace Src\Finanzas\Domain\Contracts;

use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;

interface ConceptoCobroRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array{items: list<array<string, mixed>>, total: int, currentPage: int, lastPage: int, perPage: int} */
    public function list(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $conceptoId): array;

    /** @return list<array{id: string, nombre: string}> */
    public function buildingOptions(string $userId): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $edificioId, string $conceptoId, array $data): void;

    public function changeStatus(
        string $userId,
        string $edificioId,
        string $conceptoId,
        EstadoConceptoCobro $estado,
    ): void;
}
