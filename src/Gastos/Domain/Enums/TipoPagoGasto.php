<?php

namespace Src\Gastos\Domain\Enums;

enum TipoPagoGasto: string
{
    case CONTADO = 'contado';
    case CREDITO = 'credito';
}
