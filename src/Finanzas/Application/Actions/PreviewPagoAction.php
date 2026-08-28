<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;

final readonly class PreviewPagoAction
{
    public function __construct(private PagoRepositoryInterface $pagos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $departamentoId, string $valor, string $fechaPago): array
    {
        return $this->pagos->preview($userId, $edificioId, $departamentoId, $valor, $fechaPago);
    }
}
