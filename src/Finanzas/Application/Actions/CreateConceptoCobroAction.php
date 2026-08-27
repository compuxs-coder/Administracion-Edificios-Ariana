<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;

final readonly class CreateConceptoCobroAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->conceptos->create($userId, $edificioId, $data);
    }
}
