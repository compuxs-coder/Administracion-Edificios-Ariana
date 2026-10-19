<?php

namespace Src\Comunicacion\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Src\Comunicacion\Application\Actions\EnviarEntregaComunicadoAction;

final class EnviarEntregaComunicadoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(EnviarEntregaComunicadoAction $send): void
    {
        $send->execute($this->deliveryId);
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('comunicacion.delivery_lease_seconds'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
