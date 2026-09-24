<?php

namespace Src\Tesoreria\Domain\Enums;

enum EstadoCuentaTesoreria: string
{
    case ACTIVA = 'activa';
    case INACTIVA = 'inactiva';
}
