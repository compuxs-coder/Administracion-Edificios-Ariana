<?php

namespace Src\Finanzas\Domain\Enums;

enum EstadoLoteGeneracion: string
{
    case PENDIENTE = 'pendiente';
    case PROCESANDO = 'procesando';
    case COMPLETADO = 'completado';
    case COMPLETADO_CON_ERRORES = 'completado_con_errores';
    case FALLIDO = 'fallido';
}
