<?php

namespace Src\Tesoreria\Domain\Enums;

enum TipoCuentaTesoreria: string
{
    case BANCARIA = 'bancaria';
    case CAJA = 'caja';
}
