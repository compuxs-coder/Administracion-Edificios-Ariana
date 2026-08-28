<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;

final readonly class GetPagoAction
{
    public function __construct(private PagoRepositoryInterface $pagos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $pagoId): array
    {
        return $this->pagos->get($userId, $edificioId, $pagoId);
    }
}
