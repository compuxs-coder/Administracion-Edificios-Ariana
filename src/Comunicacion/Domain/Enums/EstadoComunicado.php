<?php

namespace Src\Comunicacion\Domain\Enums;

enum EstadoComunicado: string
{
    case BORRADOR = 'borrador';
    case PUBLICADO = 'publicado';
    case ARCHIVADO = 'archivado';
}
