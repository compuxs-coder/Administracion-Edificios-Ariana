<?php

namespace Src\Edificio\Domain\Enums;

enum PermisoEdificio: string
{
    case EDIFICIO_VER = 'edificio.ver';
    case EDIFICIO_EDITAR = 'edificio.editar';
    case EDIFICIO_CAMBIAR_ESTADO = 'edificio.cambiar_estado';
    case MIEMBROS_VER = 'miembros.ver';
    case MIEMBROS_GESTIONAR = 'miembros.gestionar';
    case ESTRUCTURA_VER = 'estructura.ver';
    case ESTRUCTURA_GESTIONAR = 'estructura.gestionar';
    case PROPIEDAD_VER = 'propiedad.ver';
    case PROPIEDAD_GESTIONAR = 'propiedad.gestionar';
    case FINANZAS_VER = 'finanzas.ver';
    case CONCEPTOS_GESTIONAR = 'conceptos.gestionar';
    case LECTURAS_REGISTRAR = 'lecturas.registrar';
    case CARGOS_GENERAR = 'cargos.generar';
    case CARGOS_CREAR = 'cargos.crear';
    case CARGOS_ANULAR = 'cargos.anular';
    case PAGOS_REGISTRAR = 'pagos.registrar';
    case PAGOS_APLICAR_SALDO = 'pagos.aplicar_saldo';
    case PAGOS_ANULAR = 'pagos.anular';
    case COMPROBANTES_VER = 'comprobantes.ver';
    case EVIDENCIAS_GESTIONAR = 'evidencias.gestionar';
    case GASTOS_VER = 'gastos.ver';
    case GASTOS_GESTIONAR = 'gastos.gestionar';
    case GASTOS_ANULAR = 'gastos.anular';
    case DESEMBOLSOS_VER = 'desembolsos.ver';
    case DESEMBOLSOS_REGISTRAR = 'desembolsos.registrar';
    case DESEMBOLSOS_ANULAR = 'desembolsos.anular';
}
