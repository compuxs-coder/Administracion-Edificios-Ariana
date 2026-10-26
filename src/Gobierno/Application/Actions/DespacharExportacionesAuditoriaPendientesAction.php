<?php

namespace Src\Gobierno\Application\Actions;

use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Infrastructure\Jobs\GenerarExportacionAuditoriaJob;

final class DespacharExportacionesAuditoriaPendientesAction
{
    public function __construct(private readonly ExportacionAuditoriaRepositoryInterface $exports) {}

    public function execute(int $limit = 100): int
    {
        $ids = $this->exports->dueIds($limit);
        foreach ($ids as $id) {
            GenerarExportacionAuditoriaJob::dispatch($id)
                ->onConnection((string) config('gobierno.exports.queue_connection'))
                ->onQueue((string) config('gobierno.exports.queue'));
        }

        return count($ids);
    }
}
