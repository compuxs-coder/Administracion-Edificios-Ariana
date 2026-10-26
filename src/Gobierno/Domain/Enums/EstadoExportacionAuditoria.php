<?php

namespace Src\Gobierno\Domain\Enums;

enum EstadoExportacionAuditoria: string
{
    case PENDIENTE = 'pendiente';
    case PROCESANDO = 'procesando';
    case COMPLETADA = 'completada';
    case FALLIDA = 'fallida';
    case EXPIRADA = 'expirada';
}
