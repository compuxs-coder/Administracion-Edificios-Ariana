<?php

namespace Src\Edificio\Domain\Contracts;

use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Enums\TipoAnexo;

interface EstructuraRepositoryInterface
{
    /** @return array<string, mixed> */
    public function getForEdificio(string $edificioId): array;

    /** @param array<string, mixed> $data */
    public function saveTorre(string $edificioId, ?string $torreId, array $data): array;

    /** @param array<string, mixed> $data */
    public function savePiso(string $edificioId, ?string $pisoId, array $data): array;

    /** @param array<string, mixed> $data */
    public function saveAnexo(
        TipoAnexo $tipo,
        string $edificioId,
        ?string $anexoId,
        array $data,
    ): array;

    public function changeTorreStatus(
        string $edificioId,
        string $torreId,
        EstadoEstructura $estado,
    ): void;

    public function changePisoStatus(
        string $edificioId,
        string $pisoId,
        EstadoEstructura $estado,
    ): void;

    public function changeAnexoStatus(
        TipoAnexo $tipo,
        string $edificioId,
        string $anexoId,
        EstadoEstructura $estado,
    ): void;
}
