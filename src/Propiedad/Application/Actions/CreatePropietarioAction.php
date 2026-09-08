<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;

final readonly class CreatePropietarioAction
{
    public function __construct(private PropietarioRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->createForEdificio($userId, $edificioId, $data);
    }
}
