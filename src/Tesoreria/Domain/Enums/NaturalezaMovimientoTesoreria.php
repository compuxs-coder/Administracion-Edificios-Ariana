<?php

namespace Src\Tesoreria\Domain\Enums;

enum NaturalezaMovimientoTesoreria: string
{
    case INGRESO = 'ingreso';
    case EGRESO = 'egreso';
}
