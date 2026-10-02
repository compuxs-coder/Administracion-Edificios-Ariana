<?php

namespace Src\Operaciones\Application\Actions;

use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;

final readonly class UpdateOrdenOperativaAction
{
    public function __construct(private OrdenOperativaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $userId, string $edificioId, string $ordenId, array $data): void
    {
        $this->repository->update($userId, $edificioId, $ordenId, $data);
    }
}
