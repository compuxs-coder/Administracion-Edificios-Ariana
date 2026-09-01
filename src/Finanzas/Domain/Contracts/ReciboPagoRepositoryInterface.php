<?php

namespace Src\Finanzas\Domain\Contracts;

interface ReciboPagoRepositoryInterface
{
    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $pagoId): array;

    /** @param array{archivo: \Illuminate\Http\UploadedFile, descripcion: ?string} $data @return array{id: string} */
    public function storeEvidence(string $userId, string $edificioId, string $pagoId, array $data): array;

    /** @return array{path: string, nombre: string, mimeType: string} */
    public function evidenceDownload(string $userId, string $edificioId, string $pagoId, string $evidenciaId): array;
}
