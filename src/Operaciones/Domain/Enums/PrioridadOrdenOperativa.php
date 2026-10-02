<?php

namespace Src\Operaciones\Domain\Enums;

enum PrioridadOrdenOperativa: string
{
    case BAJA = 'baja';
    case MEDIA = 'media';
    case ALTA = 'alta';
    case CRITICA = 'critica';
}
