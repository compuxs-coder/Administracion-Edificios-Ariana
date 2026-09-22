<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;

final readonly class GetDesembolsoAction
{
    public function __construct(private DesembolsoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $desembolsoId): array
    {
        return $this->repository->get($userId, $edificioId, $desembolsoId);
    }
}
