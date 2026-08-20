<?php

namespace Src\Edificio\Domain\Exceptions;

use RuntimeException;

final class EdificioNotFoundException extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct("El edificio {$id} no fue encontrado.");
    }
}
