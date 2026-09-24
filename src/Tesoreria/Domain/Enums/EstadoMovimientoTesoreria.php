<?php

namespace Src\Tesoreria\Domain\Enums;

enum EstadoMovimientoTesoreria: string
{
    case REGISTRADO = 'registrado';
    case ANULADO = 'anulado';
}
