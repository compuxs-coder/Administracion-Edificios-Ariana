<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;

final readonly class CreateCargoManualAction
{
    public function __construct(private CargoRepositoryInterface $cargos) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->cargos->createManual($userId, $edificioId, $data);
    }
}
