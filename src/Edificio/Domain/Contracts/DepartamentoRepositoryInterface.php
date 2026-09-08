<?php

namespace Src\Edificio\Domain\Contracts;

use Src\Edificio\Domain\Enums\EstadoEstructura;

interface DepartamentoRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed>|null */
    public function findForEdificio(string $edificioId, string $departamentoId): ?array;

    /** @return array<string, mixed> */
    public function formOptionsForUser(string $userId, bool $forManagement): array;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $parqueaderoIds
     * @param list<string> $bodegaIds
     * @return array<string, mixed>
     */
    public function create(
        string $edificioId,
        array $data,
        array $parqueaderoIds,
        array $bodegaIds,
    ): array;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $parqueaderoIds
     * @param list<string> $bodegaIds
     * @return array<string, mixed>
     */
    public function update(
        string $edificioId,
        string $departamentoId,
        array $data,
        array $parqueaderoIds,
        array $bodegaIds,
    ): array;

    public function changeStatus(
        string $edificioId,
        string $departamentoId,
        EstadoEstructura $estado,
    ): void;
}
