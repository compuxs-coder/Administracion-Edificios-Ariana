<?php

namespace Src\Gastos\Domain\Enums;

enum EstadoContratoProveedor: string
{
    case BORRADOR = 'borrador';
    case REGISTRADO = 'registrado';
    case ANULADO = 'anulado';
}
