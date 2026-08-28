<?php

namespace Src\Finanzas\Domain\Enums;

enum OrigenCargo: string
{
    case AUTOMATICO = 'automatico';
    case MANUAL = 'manual';
    case IMPORTADO = 'importado';
    case AJUSTE = 'ajuste';
}
