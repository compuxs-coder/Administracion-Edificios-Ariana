<?php

namespace Src\Finanzas\Domain\Contracts;

interface TarifaConceptoRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(string $userId, string $edificioId, string $conceptoId, array $data): void;
}
