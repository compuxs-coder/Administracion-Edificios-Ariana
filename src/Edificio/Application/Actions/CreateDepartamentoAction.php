<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;

final readonly class CreateDepartamentoAction
{
    public function __construct(private DepartamentoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $edificioId, array $data): array
    {
        return $this->repository->create(
            $edificioId,
            $data,
            array_values($data['parqueaderos'] ?? []),
            array_values($data['bodegas'] ?? []),
        );
    }
}
