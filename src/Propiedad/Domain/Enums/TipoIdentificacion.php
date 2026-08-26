<?php

namespace Src\Propiedad\Domain\Enums;

enum TipoIdentificacion: string
{
    case CEDULA = 'cedula';
    case RUC = 'ruc';
    case PASAPORTE = 'pasaporte';
    case OTRO = 'otro';
}
