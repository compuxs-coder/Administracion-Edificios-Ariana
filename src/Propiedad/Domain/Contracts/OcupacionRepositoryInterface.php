<?php

namespace Src\Propiedad\Domain\Contracts;

interface OcupacionRepositoryInterface
{
    /** @return array<string, mixed> */
    public function getForDepartamento(string $userId, string $edificioId, string $departamentoId): array;

    /** @param array<string, mixed> $data */
    public function assign(string $userId, string $edificioId, string $departamentoId, array $data): void;

    /** @param array<string, mixed> $data */
    public function finalize(
        string $userId,
        string $edificioId,
        string $departamentoId,
        string $ocupacionId,
        array $data,
    ): void;
}
