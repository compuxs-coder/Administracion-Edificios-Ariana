<?php

namespace Src\Finanzas\Domain\Enums;

enum TipoConceptoCobro: string
{
    case ORDINARIO = 'ordinario';
    case EXTRAORDINARIO = 'extraordinario';
    case CONSUMO = 'consumo';
    case MULTA = 'multa';
    case INTERES = 'interes';
    case OTRO = 'otro';
}
