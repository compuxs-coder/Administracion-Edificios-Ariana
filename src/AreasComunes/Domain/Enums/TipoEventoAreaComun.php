<?php

namespace Src\AreasComunes\Domain\Enums;

enum TipoEventoAreaComun: string
{
    case ESPACIO_CREADO = 'espacio_creado';
    case ESPACIO_ACTUALIZADO = 'espacio_actualizado';
    case ESPACIO_ACTIVADO = 'espacio_activado';
    case ESPACIO_INACTIVADO = 'espacio_inactivado';
    case REGLA_CREADA = 'regla_creada';
    case RESERVA_CREADA = 'reserva_creada';
    case RESERVA_APROBADA = 'reserva_aprobada';
    case RESERVA_RECHAZADA = 'reserva_rechazada';
    case RESERVA_CANCELADA = 'reserva_cancelada';
}
