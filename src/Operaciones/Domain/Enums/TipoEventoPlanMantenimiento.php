<?php

namespace Src\Operaciones\Domain\Enums;

enum TipoEventoPlanMantenimiento: string
{
    case CREACION = 'creacion';
    case CAMBIO_DATOS = 'cambio_datos';
    case ACTIVACION = 'activacion';
    case PAUSA = 'pausa';
    case GENERACION = 'generacion';
    case BLOQUEO = 'bloqueo';
    case REINTENTO = 'reintento';
    case OMISION = 'omision';
}
