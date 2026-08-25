<?php

namespace Src\Edificio\Application\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;

final readonly class GetDepartamentoAction
{
    public function __construct(private DepartamentoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $edificioId, string $departamentoId): array
    {
        return $this->repository->findForEdificio($edificioId, $departamentoId)
            ?? throw (new ModelNotFoundException())->setModel('Departamento', [$departamentoId]);
    }
}
