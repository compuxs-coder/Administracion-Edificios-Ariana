<?php

namespace Src\Gobierno\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Src\Gobierno\Application\Actions\GenerarExportacionAuditoriaAction;

final class GenerarExportacionAuditoriaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 600;

    public function __construct(public readonly string $exportId) {}

    public function handle(GenerarExportacionAuditoriaAction $generate): void
    {
        $generate->execute($this->exportId);
    }

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('gobierno.exports.lease_seconds'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
