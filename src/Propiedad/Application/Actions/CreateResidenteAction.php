<?php

namespace Src\Propiedad\Application\Actions;

use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;

final readonly class CreateResidenteAction
{
    public function __construct(private ResidenteRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, array $data): array
    {
        return $this->repository->createForEdificio($userId, $edificioId, $data);
    }
}
