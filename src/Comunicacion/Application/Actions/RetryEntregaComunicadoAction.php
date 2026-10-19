<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;
use Src\Comunicacion\Infrastructure\Jobs\EnviarEntregaComunicadoJob;

final readonly class RetryEntregaComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    public function execute(string $userId, string $buildingId, string $messageId, string $deliveryId): void
    {
        $this->repository->retryDelivery($userId, $buildingId, $messageId, $deliveryId);
        EnviarEntregaComunicadoJob::dispatch($deliveryId)->afterCommit();
    }
}
