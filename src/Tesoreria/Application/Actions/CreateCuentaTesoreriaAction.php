<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;

final readonly class CreateCuentaTesoreriaAction
{
    public function __construct(private CuentaTesoreriaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $data);
    }
}
