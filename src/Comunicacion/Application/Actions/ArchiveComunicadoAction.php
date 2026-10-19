<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class ArchiveComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $messageId): void
    {
        $this->repository->archive($userId, $buildingId, $messageId);
    }
}
