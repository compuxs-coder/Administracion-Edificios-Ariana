<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class RecordActuacionOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $ordenId, string $description): void
    {
        $this->repository->recordManualAction($userId, $edificioId, $ordenId, $description);
    }
}
