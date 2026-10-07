<?php

namespace Src\Operaciones\Domain\Enums;

enum EstadoOcurrenciaMantenimientoPreventivo: string
{
    case PENDIENTE = 'pendiente';
    case GENERADA = 'generada';
    case BLOQUEADA = 'bloqueada';
    case OMITIDA = 'omitida';
}
