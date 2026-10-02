<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class ReopenOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $ordenId, string $reason): void
    {
        $this->repository->reopen($userId, $edificioId, $ordenId, $reason);
    }
}
