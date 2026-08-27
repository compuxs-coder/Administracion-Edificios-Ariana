<?php

namespace Src\Finanzas\Domain\Enums;

enum FormaCalculoCobro: string
{
    case VALOR_FIJO = 'valor_fijo';
    case POR_ALICUOTA = 'por_alicuota';
    case PORCENTAJE = 'porcentaje';
    case POR_CONSUMO = 'por_consumo';
    case MANUAL = 'manual';
}
