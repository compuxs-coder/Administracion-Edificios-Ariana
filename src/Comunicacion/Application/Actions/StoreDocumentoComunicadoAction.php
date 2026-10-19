<?php

namespace Src\Comunicacion\Application\Actions;

use Illuminate\Http\UploadedFile;
use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class StoreDocumentoComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @return array{id: string} */
    public function execute(string $userId, string $buildingId, string $messageId, UploadedFile $file, ?string $description): array
    {
        return $this->repository->storeDocument($userId, $buildingId, $messageId, $file, $description);
    }
}
