<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;

final readonly class CancelPagoAction
{
    public function __construct(private PagoRepositoryInterface $pagos) {}

    public function execute(string $userId, string $edificioId, string $pagoId, string $motivo): void
    {
        $this->pagos->cancel($userId, $edificioId, $pagoId, $motivo);
    }
}
