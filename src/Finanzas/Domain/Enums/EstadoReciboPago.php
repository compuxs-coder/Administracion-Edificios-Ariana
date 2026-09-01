<?php

namespace Src\Finanzas\Domain\Enums;

enum EstadoReciboPago: string
{
    case EMITIDO = 'emitido';
    case ANULADO = 'anulado';
}
