<?php

namespace Src\Operaciones\Domain\Enums;

enum OrigenOrdenOperativa: string
{
    case MANUAL = 'manual';
    case PROGRAMACION_PREVENTIVA = 'programacion_preventiva';
}
