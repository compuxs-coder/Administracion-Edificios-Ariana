<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;

final readonly class UpdateConceptoCobroAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $conceptoId, array $data): void
    {
        $this->conceptos->update($userId, $edificioId, $conceptoId, $data);
    }
}
