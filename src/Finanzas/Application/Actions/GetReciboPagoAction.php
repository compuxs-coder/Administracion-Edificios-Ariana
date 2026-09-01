<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ReciboPagoRepositoryInterface;

final readonly class GetReciboPagoAction
{
    public function __construct(private ReciboPagoRepositoryInterface $recibos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $pagoId): array
    {
        return $this->recibos->get($userId, $edificioId, $pagoId);
    }
}
