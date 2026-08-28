<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class ListCargosAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->cargos->list($userId, $filters);
    }
}
