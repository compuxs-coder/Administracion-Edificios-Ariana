<?php

namespace Src\Gastos\Domain\Enums;

enum EstadoProveedor: string
{
    case ACTIVO = 'activo';
    case INACTIVO = 'inactivo';
}
