<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\LecturaConsumoRepositoryInterface;

final readonly class ListLecturasConsumoAction
{
    public function __construct(private LecturaConsumoRepositoryInterface $lecturas) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->lecturas->list($userId, $filters);
    }
}
