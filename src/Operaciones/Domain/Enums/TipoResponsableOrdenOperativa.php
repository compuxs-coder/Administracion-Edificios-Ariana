<?php

namespace Src\Operaciones\Domain\Enums;

enum TipoResponsableOrdenOperativa: string
{
    case USUARIO = 'usuario';
    case PROVEEDOR = 'proveedor';
}
