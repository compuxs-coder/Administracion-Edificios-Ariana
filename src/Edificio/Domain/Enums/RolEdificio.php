<?php

namespace Src\Edificio\Domain\Enums;

enum RolEdificio: string
{
    case ADMINISTRADOR = 'administrador';
    case GESTOR_PROPIEDAD = 'gestor_propiedad';
    case GESTOR_FINANZAS = 'gestor_finanzas';
    case CONSULTA = 'consulta';
}
