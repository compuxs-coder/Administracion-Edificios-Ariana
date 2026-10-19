<?php

namespace Src\Comunicacion\Application\Actions;

use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;

final readonly class CreateComunicadoAction
{
    public function __construct(private ComunicacionRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array{id: string} */
    public function execute(string $userId, string $buildingId, array $data): array
    {
        return $this->repository->create($userId, $buildingId, $data);
    }
}
