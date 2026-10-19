<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\CanalNotificacionComunicadoInterface;
use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class EnviarEntregaComunicadoAction
{
    public function __construct(
        private ComunicacionRepositoryInterface $repository,
        private CanalNotificacionComunicadoInterface $channel,
    ) {}

    public function execute(string $deliveryId): void
    {
        $delivery = $this->repository->claimDelivery($deliveryId);
        if ($delivery === null) {
            return;
        }
        try {
            $providerMessageId = $this->channel->send($delivery);
            $this->repository->markDeliverySent($deliveryId, $delivery['attempt'], $providerMessageId);
        } catch (\Throwable $exception) {
            $this->repository->markDeliveryFailure($deliveryId, $delivery['attempt'], $exception);
            throw $exception;
        }
    }
}
