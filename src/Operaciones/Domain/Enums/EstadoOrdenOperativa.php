<?php

namespace Src\Operaciones\Domain\Enums;

enum EstadoOrdenOperativa: string
{
    case REPORTADA = 'reportada';
    case EN_REVISION = 'en_revision';
    case EN_PROGRESO = 'en_progreso';
    case RESUELTA = 'resuelta';
    case CERRADA = 'cerrada';
    case CANCELADA = 'cancelada';

    public function permiteEditar(): bool
    {
        return in_array($this, [self::REPORTADA, self::EN_REVISION, self::EN_PROGRESO], true);
    }

    public function permiteAportes(): bool
    {
        return in_array($this, [self::REPORTADA, self::EN_REVISION, self::EN_PROGRESO, self::RESUELTA], true);
    }
}
