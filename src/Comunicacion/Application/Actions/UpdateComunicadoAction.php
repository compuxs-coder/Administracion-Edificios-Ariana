<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class UpdateComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $buildingId, string $messageId, array $data): void
    {
        $this->repository->update($userId, $buildingId, $messageId, $data);
    }
}
