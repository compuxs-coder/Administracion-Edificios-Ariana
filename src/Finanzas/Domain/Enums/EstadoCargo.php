<?php

namespace Src\Finanzas\Domain\Enums;

enum EstadoCargo: string
{
    case PENDIENTE = 'pendiente';
    case PARCIAL = 'parcial';
    case PAGADO = 'pagado';
    case ANULADO = 'anulado';
}
