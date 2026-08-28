<?php

namespace Src\Finanzas\Domain\Enums;

enum FormaPago: string
{
    case EFECTIVO = 'efectivo';
    case TRANSFERENCIA = 'transferencia';
    case DEPOSITO = 'deposito';
    case TARJETA = 'tarjeta';
    case CHEQUE = 'cheque';
    case OTRO = 'otro';
}
