<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ReciboPagoRepositoryInterface;

final readonly class GetEvidenciaPagoDownloadAction
{
    public function __construct(private ReciboPagoRepositoryInterface $recibos) {}

    /** @return array{path: string, nombre: string, mimeType: string} */
    public function execute(string $userId, string $edificioId, string $pagoId, string $evidenciaId): array
    {
        return $this->recibos->evidenceDownload($userId, $edificioId, $pagoId, $evidenciaId);
    }
}
