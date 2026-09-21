<?php

namespace Src\Gastos\Domain\Enums;

enum EstadoPagoGasto: string
{
    case PENDIENTE = 'pendiente';
    case PAGADO = 'pagado';
    case ANULADO = 'anulado';
}
