<?php

namespace Src\Edificio\Domain\Enums;

enum RolEdificio: string
{
    case ADMINISTRADOR = 'administrador';
    case GESTOR_PROPIEDAD = 'gestor_propiedad';
    case GESTOR_FINANZAS = 'gestor_finanzas';
    case GESTOR_OPERACIONES = 'gestor_operaciones';
    case GESTOR_AREAS_COMUNES = 'gestor_areas_comunes';
    case GESTOR_COMUNICACION = 'gestor_comunicacion';
    case CONSULTA = 'consulta';
}
