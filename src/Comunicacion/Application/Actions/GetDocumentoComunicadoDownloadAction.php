<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class GetDocumentoComunicadoDownloadAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @return array{contents: string, nombre: string, mimeType: string} */
    public function authorized(string $userId, string $buildingId, string $messageId, string $documentId): array
    {
        return $this->repository->documentDownload($userId, $buildingId, $messageId, $documentId);
    }

    /** @return array{contents: string, nombre: string, mimeType: string} */
    public function signed(string $deliveryId, string $documentId): array
    {
        return $this->repository->signedDocumentDownload($deliveryId, $documentId);
    }
}
