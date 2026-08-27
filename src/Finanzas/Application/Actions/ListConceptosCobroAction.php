<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;

final readonly class ListConceptosCobroAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->conceptos->list($userId, $filters);
    }
}
