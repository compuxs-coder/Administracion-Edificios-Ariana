<?php

namespace Src\Tesoreria\Domain\Enums;

enum EstadoEfectivoMovimientoTesoreria: string
{
    case PENDIENTE = 'pendiente';
    case CONCILIADO = 'conciliado';
    case NO_APLICA = 'no_aplica';
    case ANULADO = 'anulado';
}
