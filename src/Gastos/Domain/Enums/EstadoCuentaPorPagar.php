<?php

namespace Src\Gastos\Domain\Enums;

enum EstadoCuentaPorPagar: string
{
    case PENDIENTE = 'pendiente';
    case ANULADA = 'anulada';
}
