<?php

namespace Src\Finanzas\Domain\Enums;

enum EstadoConceptoCobro: string
{
    case ACTIVO = 'activo';
    case INACTIVO = 'inactivo';
}
