<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Enums\TipoAnexo;
use Src\Edificio\Domain\Enums\TipoElementoEstructura;

final readonly class ChangeEstructuraStatusAction
{
    public function __construct(private EstructuraRepositoryInterface $repository) {}

    public function execute(
        TipoElementoEstructura $tipo,
        string $edificioId,
        string $elementoId,
        EstadoEstructura $estado,
    ): void {
        match ($tipo) {
            TipoElementoEstructura::TORRE => $this->repository->changeTorreStatus($edificioId, $elementoId, $estado),
            TipoElementoEstructura::PISO => $this->repository->changePisoStatus($edificioId, $elementoId, $estado),
            TipoElementoEstructura::PARQUEADERO => $this->repository->changeAnexoStatus(TipoAnexo::PARQUEADERO, $edificioId, $elementoId, $estado),
            TipoElementoEstructura::BODEGA => $this->repository->changeAnexoStatus(TipoAnexo::BODEGA, $edificioId, $elementoId, $estado),
        };
    }
}
