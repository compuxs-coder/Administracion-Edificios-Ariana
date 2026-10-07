<?php

namespace Src\Operaciones\Domain\Enums;

enum EstadoPlanMantenimientoPreventivo: string
{
    case INACTIVO = 'inactivo';
    case ACTIVO = 'activo';
}
