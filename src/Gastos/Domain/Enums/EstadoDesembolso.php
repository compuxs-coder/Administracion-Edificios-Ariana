<?php

namespace Src\Gastos\Domain\Enums;

enum EstadoDesembolso: string
{
    case PREPARANDO = 'preparando';
    case REGISTRADO = 'registrado';
    case ANULADO = 'anulado';
}
