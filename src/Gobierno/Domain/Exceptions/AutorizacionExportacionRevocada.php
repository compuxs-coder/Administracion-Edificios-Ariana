<?php

namespace Src\Gobierno\Domain\Exceptions;

use RuntimeException;

final class AutorizacionExportacionRevocada extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Los permisos de la exportación cambiaron durante el procesamiento.');
    }
}
