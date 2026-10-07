<?php

namespace Src\Operaciones\Domain\Enums;

enum TipoOrdenOperativa: string
{
    case INCIDENCIA = 'incidencia';
    case SOLICITUD = 'solicitud';
    case MANTENIMIENTO_PREVENTIVO = 'mantenimiento_preventivo';
}
