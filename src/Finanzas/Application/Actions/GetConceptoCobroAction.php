<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;

final readonly class GetConceptoCobroAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $conceptoId): array
    {
        return $this->conceptos->get($userId, $edificioId, $conceptoId);
    }
}
