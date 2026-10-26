<?php

namespace Src\Gobierno\Domain\Enums;

enum TipoEventoExportacionAuditoria: string
{
    case SOLICITADA = 'solicitada';
    case PROCESAMIENTO_INICIADO = 'procesamiento_iniciado';
    case LEASE_RECUPERADO = 'lease_recuperado';
    case COMPLETADA = 'completada';
    case FALLIDA = 'fallida';
    case DESCARGADA = 'descargada';
    case EXPIRADA = 'expirada';
    case ARCHIVO_ELIMINADO = 'archivo_eliminado';
}
