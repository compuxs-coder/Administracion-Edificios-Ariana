<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class DeleteDocumentoComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $messageId, string $documentId): void
    {
        $this->repository->deleteDocument($userId, $buildingId, $messageId, $documentId);
    }
}
