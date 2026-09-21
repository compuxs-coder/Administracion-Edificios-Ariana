<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;

final readonly class CreateGastoAction
{
    public function __construct(private GastoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $data);
    }
}
