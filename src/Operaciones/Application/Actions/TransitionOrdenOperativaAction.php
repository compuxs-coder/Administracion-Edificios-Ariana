<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class TransitionOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $ordenId, string $state): void
    {
        $this->repository->transition($userId, $edificioId, $ordenId, $state);
    }
}
