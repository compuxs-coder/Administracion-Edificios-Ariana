<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;

final readonly class GetConceptoCobroOptionsAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    /** @return list<array{id: string, nombre: string}> */
    public function buildings(string $userId): array
    {
        return $this->conceptos->buildingOptions($userId);
    }
}
