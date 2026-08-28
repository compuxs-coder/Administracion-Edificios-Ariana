<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;

final readonly class CreatePagoAction
{
    public function __construct(private PagoRepositoryInterface $pagos) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->pagos->create($userId, $edificioId, $data);
    }
}
