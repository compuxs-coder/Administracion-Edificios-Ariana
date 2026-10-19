<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;
use Src\Comunicacion\Infrastructure\Jobs\EnviarEntregaComunicadoJob;

final readonly class DispatchDueComunicadoDeliveriesAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    public function execute(int $limit = 500): int
    {
        $ids = $this->repository->dueDeliveryIds(null, $limit);
        foreach ($ids as $id) {
            EnviarEntregaComunicadoJob::dispatch($id);
        }

        return count($ids);
    }
}
