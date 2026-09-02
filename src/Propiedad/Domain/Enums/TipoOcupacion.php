<?php

namespace Src\Propiedad\Domain\Enums;

enum TipoOcupacion: string
{
    case PROPIETARIO_OCUPANTE = 'propietario_ocupante';
    case ARRENDATARIO = 'arrendatario';
    case OTRO = 'otro';
}
