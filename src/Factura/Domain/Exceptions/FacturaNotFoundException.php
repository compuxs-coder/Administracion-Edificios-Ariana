<?php

namespace Src\Factura\Domain\Exceptions;

use RuntimeException;

final class FacturaNotFoundException extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct("Factura con id {$id} no encontrada.");
    }
}
