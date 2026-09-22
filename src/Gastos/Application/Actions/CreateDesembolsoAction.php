<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;

final readonly class CreateDesembolsoAction
{
    public function __construct(private DesembolsoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $data);
    }
}
