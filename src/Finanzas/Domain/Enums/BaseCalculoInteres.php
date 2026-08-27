<?php

namespace Src\Finanzas\Domain\Enums;

enum BaseCalculoInteres: string
{
    case SALDO_VENCIDO = 'saldo_vencido';
    case CAPITAL_VENCIDO = 'capital_vencido';
    case SALDO_TOTAL = 'saldo_total';
}
