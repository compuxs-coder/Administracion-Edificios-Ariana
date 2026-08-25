<?php

namespace Src\Edificio\Domain\Enums;

enum TipoElementoEstructura: string
{
    case TORRE = 'torre';
    case PISO = 'piso';
    case PARQUEADERO = 'parqueadero';
    case BODEGA = 'bodega';
}
