<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class AssignOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $ordenId, string $type, string $responsibleId): void
    {
        $this->repository->assign($userId, $edificioId, $ordenId, $type, $responsibleId);
    }
}
