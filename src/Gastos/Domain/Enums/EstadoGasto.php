<?php

namespace Src\Gastos\Domain\Enums;

enum EstadoGasto: string
{
    case BORRADOR = 'borrador';
    case REGISTRADO = 'registrado';
    case ANULADO = 'anulado';
}
