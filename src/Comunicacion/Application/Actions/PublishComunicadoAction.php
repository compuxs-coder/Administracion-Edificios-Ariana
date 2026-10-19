<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;
use Src\Comunicacion\Infrastructure\Jobs\DespacharEntregasComunicadoJob;

final readonly class PublishComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @return array{id: string} */
    public function execute(string $userId, string $buildingId, string $messageId, string $expectedUpdatedAt): array
    {
        $result = $this->repository->publish($userId, $buildingId, $messageId, $expectedUpdatedAt);
        DespacharEntregasComunicadoJob::dispatch($result['id'])->afterCommit();

        return $result;
    }
}
