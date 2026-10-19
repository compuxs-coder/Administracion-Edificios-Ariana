<?php

namespace Src\Comunicacion\Domain\Contracts;

interface DestinatariosComunicacionReadInterface
{
    /**
     * @return list<array{terceroId: string, nombre: string, email: ?string, esPropietario: bool, esResidente: bool, departamentos: list<string>}>
     */
    public function resolve(
        string $buildingId,
        string $localDate,
        bool $includeOwners,
        bool $includeResidents,
    ): array;
}
