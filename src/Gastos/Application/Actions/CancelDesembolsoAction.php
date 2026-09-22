<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;

final readonly class CancelDesembolsoAction
{
    public function __construct(private DesembolsoRepositoryInterface $repository) {}

    public function execute(string $userId, string $edificioId, string $desembolsoId, string $reason): void
    {
        $this->repository->cancel($userId, $edificioId, $desembolsoId, $reason);
    }
}
