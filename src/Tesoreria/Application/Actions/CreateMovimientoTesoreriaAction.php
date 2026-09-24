<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;

final readonly class CreateMovimientoTesoreriaAction
{
    public function __construct(private MovimientoTesoreriaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $edificioId, string $cuentaId, array $data): array
    {
        return $this->repository->create($userId, $edificioId, $cuentaId, $data);
    }
}
