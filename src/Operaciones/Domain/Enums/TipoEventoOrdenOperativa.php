<?php

namespace Src\Operaciones\Domain\Enums;

enum TipoEventoOrdenOperativa: string
{
    case CREACION = 'creacion';
    case CAMBIO_DATOS = 'cambio_datos';
    case CAMBIO_ESTADO = 'cambio_estado';
    case ASIGNACION = 'asignacion';
    case REASIGNACION = 'reasignacion';
    case CANCELACION = 'cancelacion';
    case REAPERTURA = 'reapertura';
    case EVIDENCIA = 'evidencia';
    case ACTUACION_MANUAL = 'actuacion_manual';
}
