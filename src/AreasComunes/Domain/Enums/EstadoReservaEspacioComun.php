<?php

namespace Src\AreasComunes\Domain\Enums;

enum EstadoReservaEspacioComun: string
{
    case PENDIENTE = 'pendiente';
    case CONFIRMADA = 'confirmada';
    case RECHAZADA = 'rechazada';
    case CANCELADA = 'cancelada';
}
