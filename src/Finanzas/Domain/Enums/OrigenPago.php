<?php

namespace Src\Finanzas\Domain\Enums;

enum OrigenPago: string
{
    case MANUAL = 'manual';
    case IMPORTADO = 'importado';
    case AJUSTE = 'ajuste';
}
