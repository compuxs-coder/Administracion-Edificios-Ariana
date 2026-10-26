<?php

namespace Src\Gobierno\Domain\Exceptions;

use RuntimeException;

final class LimiteFilasExportacionExcedido extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La exportación supera el límite de filas configurado.');
    }
}
