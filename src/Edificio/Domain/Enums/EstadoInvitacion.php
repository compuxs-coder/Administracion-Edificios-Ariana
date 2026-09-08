<?php

namespace Src\Edificio\Domain\Enums;

enum EstadoInvitacion: string
{
    case PENDIENTE = 'pendiente';
    case ACEPTADA = 'aceptada';
    case REVOCADA = 'revocada';
}
