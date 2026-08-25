<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;

final readonly class UpdateDepartamentoAction
{
    public function __construct(private DepartamentoRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $edificioId, string $departamentoId, array $data): array
    {
        return $this->repository->update(
            $edificioId,
            $departamentoId,
            $data,
            array_values($data['parqueaderos'] ?? []),
            array_values($data['bodegas'] ?? []),
        );
    }
}
