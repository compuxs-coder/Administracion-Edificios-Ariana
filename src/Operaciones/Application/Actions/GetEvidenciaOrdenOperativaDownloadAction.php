<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class GetEvidenciaOrdenOperativaDownloadAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    /** @return array{contents: string, nombre: string, mimeType: string} */
    public function execute(string $userId, string $edificioId, string $ordenId, string $evidenceId): array
    {
        return $this->repository->evidenceDownload($userId, $edificioId, $ordenId, $evidenceId);
    }
}
