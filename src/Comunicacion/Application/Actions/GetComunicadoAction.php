<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class GetComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $buildingId, string $messageId, int $deliveryPage = 1, int $omittedPage = 1): array
    {
        return $this->repository->get($userId, $buildingId, $messageId, $deliveryPage, $omittedPage);
    }
}
