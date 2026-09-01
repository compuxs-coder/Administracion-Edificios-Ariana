<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ReciboPagoRepositoryInterface;

final readonly class StoreEvidenciaPagoAction
{
    public function __construct(private ReciboPagoRepositoryInterface $recibos) {}

    /** @param array{archivo: \Illuminate\Http\UploadedFile, descripcion: ?string} $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, string $pagoId, array $data): array
    {
        return $this->recibos->storeEvidence($userId, $edificioId, $pagoId, $data);
    }
}
