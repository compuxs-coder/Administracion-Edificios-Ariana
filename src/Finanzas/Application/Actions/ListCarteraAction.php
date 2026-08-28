<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;

final readonly class ListCarteraAction
{
    public function __construct(private PagoRepositoryInterface $pagos) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->pagos->cartera($userId, $filters);
    }
}
