<?php

namespace Src\Finanzas\Domain\Enums;

enum EstadoPago: string
{
    case REGISTRADO = 'registrado';
    case ANULADO = 'anulado';
}
