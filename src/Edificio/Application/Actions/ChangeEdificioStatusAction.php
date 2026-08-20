<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Entities\Edificio;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Domain\Exceptions\EdificioNotFoundException;

final readonly class ChangeEdificioStatusAction
{
    public function __construct(private EdificioRepositoryInterface $repository) {}

    public function execute(string $id, EstadoEdificio $estado): Edificio
    {
        $edificio = $this->repository->find($id)
            ?? throw new EdificioNotFoundException($id);

        $edificio->cambiarEstado($estado);

        return $this->repository->save($edificio);
    }
}
