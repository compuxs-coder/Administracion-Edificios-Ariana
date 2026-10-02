<?php

namespace Src\Operaciones\Application\Actions;

use Illuminate\Http\UploadedFile;
use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class StoreEvidenciaOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    /** @return array{id: string} */
    public function execute(string $userId, string $edificioId, string $ordenId, UploadedFile $file, ?string $description): array
    {
        return $this->repository->storeEvidence($userId, $edificioId, $ordenId, $file, $description);
    }
}
