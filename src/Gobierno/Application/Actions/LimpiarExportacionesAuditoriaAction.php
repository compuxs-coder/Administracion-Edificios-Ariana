<?php

namespace Src\Gobierno\Application\Actions;

use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;

final class LimpiarExportacionesAuditoriaAction
{
    public function __construct(private readonly ExportacionAuditoriaRepositoryInterface $exports) {}

    /** @return array{expired: int, deleted: int, errors: int} */
    public function execute(int $limit = 500): array
    {
        return $this->exports->cleanupExpired($limit);
    }
}
