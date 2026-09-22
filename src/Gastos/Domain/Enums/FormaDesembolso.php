<?php

namespace Src\Gastos\Domain\Enums;

enum FormaDesembolso: string
{
    case EFECTIVO = 'efectivo';
    case TRANSFERENCIA = 'transferencia';
    case DEPOSITO = 'deposito';
    case TARJETA = 'tarjeta';
    case CHEQUE = 'cheque';
    case OTRO = 'otro';
}
