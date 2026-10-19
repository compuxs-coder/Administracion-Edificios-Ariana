<?php

namespace Src\Comunicacion\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final class DespacharEntregasComunicadoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $messageId) {}

    public function handle(ComunicacionRepositoryInterface $repository): void
    {
        $ids = $repository->dueDeliveryIds($this->messageId, 500);
        foreach ($ids as $id) {
            EnviarEntregaComunicadoJob::dispatch($id);
        }
        if (count($ids) === 500) {
            self::dispatch($this->messageId)->delay(now()->addSecond());
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }
}
