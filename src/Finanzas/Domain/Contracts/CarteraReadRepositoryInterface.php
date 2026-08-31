<?php

namespace Src\Finanzas\Domain\Contracts;

interface CarteraReadRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginate(string $userId, array $filters): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function statement(string $userId, string $edificioId, string $departamentoId, array $filters): array;
}
