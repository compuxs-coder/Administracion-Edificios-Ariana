<?php

namespace Src\Gobierno\Domain;

final class CatalogoAuditoria
{
    public const SOURCES = ['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'];

    public const SOURCE_EVENT_TYPES = [
        'accesos' => [
            'migracion', 'membresia_creada', 'invitacion_creada', 'invitacion_aceptada',
            'invitacion_revocada', 'roles_actualizados', 'membresia_revocada',
        ],
        'operaciones' => [
            'creacion', 'cambio_datos', 'cambio_estado', 'asignacion', 'reasignacion',
            'cancelacion', 'reapertura', 'actuacion_manual', 'evidencia',
        ],
        'mantenimiento' => [
            'creacion', 'cambio_datos', 'activacion', 'pausa', 'generacion', 'bloqueo',
            'reintento', 'omision',
        ],
        'areas_comunes' => [
            'espacio_creado', 'espacio_actualizado', 'espacio_activado', 'espacio_inactivado',
            'regla_creada', 'reserva_creada', 'reserva_aprobada', 'reserva_rechazada',
            'reserva_cancelada',
        ],
        'comunicacion' => [
            'comunicado_creado', 'comunicado_actualizado', 'comunicado_publicado',
            'comunicado_archivado', 'documento_agregado', 'documento_retirado',
            'entrega_reintentada',
        ],
    ];

    public const EVENT_TYPES = [
        'migracion', 'membresia_creada', 'invitacion_creada', 'invitacion_aceptada',
        'invitacion_revocada', 'roles_actualizados', 'membresia_revocada',
        'creacion', 'cambio_datos', 'cambio_estado', 'asignacion', 'reasignacion',
        'cancelacion', 'reapertura', 'actuacion_manual', 'evidencia', 'activacion',
        'pausa', 'generacion', 'bloqueo', 'reintento', 'omision', 'espacio_creado',
        'espacio_actualizado', 'espacio_activado', 'espacio_inactivado', 'regla_creada',
        'reserva_creada', 'reserva_aprobada', 'reserva_rechazada', 'reserva_cancelada',
        'comunicado_creado', 'comunicado_actualizado', 'comunicado_publicado',
        'comunicado_archivado', 'documento_agregado', 'documento_retirado',
        'entrega_reintentada',
    ];

    private function __construct() {}
}
