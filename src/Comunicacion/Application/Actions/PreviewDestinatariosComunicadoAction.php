<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class PreviewDestinatariosComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @return array<string, int> */
    public function execute(string $userId, string $buildingId, string $messageId): array
    {
        return $this->repository->previewRecipients($userId, $buildingId, $messageId);
    }
}
