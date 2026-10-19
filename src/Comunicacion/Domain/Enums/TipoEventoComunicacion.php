<?php

namespace Src\Comunicacion\Domain\Enums;

enum TipoEventoComunicacion: string
{
    case COMUNICADO_CREADO = 'comunicado_creado';
    case COMUNICADO_ACTUALIZADO = 'comunicado_actualizado';
    case DOCUMENTO_AGREGADO = 'documento_agregado';
    case DOCUMENTO_RETIRADO = 'documento_retirado';
    case COMUNICADO_PUBLICADO = 'comunicado_publicado';
    case COMUNICADO_ARCHIVADO = 'comunicado_archivado';
    case ENTREGA_REINTENTADA = 'entrega_reintentada';
}
