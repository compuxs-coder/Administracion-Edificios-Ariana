<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\TarifaConceptoRepositoryInterface;

final readonly class CreateTarifaConceptoAction
{
    public function __construct(private TarifaConceptoRepositoryInterface $tarifas) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $conceptoId, array $data): void
    {
        $this->tarifas->create($userId, $edificioId, $conceptoId, $data);
    }
}
