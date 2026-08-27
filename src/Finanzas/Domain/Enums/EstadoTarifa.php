<?php

namespace Src\Finanzas\Domain\Enums;

enum EstadoTarifa: string
{
    case VIGENTE = 'vigente';
    case FINALIZADA = 'finalizada';
    case PROGRAMADA = 'programada';
}
