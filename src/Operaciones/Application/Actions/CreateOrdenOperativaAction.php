<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class CreateOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string, numero: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $data);
    }
}
