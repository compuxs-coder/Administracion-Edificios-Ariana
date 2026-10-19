<?php

namespace Src\Comunicacion\Domain\Enums;

enum EstadoEntregaComunicado: string
{
    case PENDIENTE = 'pendiente';
    case PROCESANDO = 'procesando';
    case REINTENTO = 'reintento';
    case ENVIADA = 'enviada';
    case FALLIDA = 'fallida';
}
