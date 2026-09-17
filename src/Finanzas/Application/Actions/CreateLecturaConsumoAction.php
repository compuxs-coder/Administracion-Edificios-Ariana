<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\LecturaConsumoRepositoryInterface;

final readonly class CreateLecturaConsumoAction
{
    public function __construct(private LecturaConsumoRepositoryInterface $lecturas) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->lecturas->create($userId, $edificioId, $data);
    }
}
