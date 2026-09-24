<?php

namespace Src\Tesoreria\Domain\Enums;

enum EstadoConciliacionTesoreria: string
{
    case VIGENTE = 'vigente';
    case REVERTIDA = 'revertida';
}
