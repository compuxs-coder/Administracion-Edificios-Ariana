<?php

namespace Src\Gobierno\Infrastructure\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final class OrdenesAuditoriaRead extends FuenteAuditoriaRead
{
    public function code(): string
    {
        return 'operaciones';
    }

    public function label(): string
    {
        return 'Operaciones';
    }

    public function eventTypes(): array
    {
        return [
            'creacion' => 'Orden creada',
            'cambio_datos' => 'Datos de orden actualizados',
            'cambio_estado' => 'Estado de orden actualizado',
            'asignacion' => 'Responsable asignado',
            'reasignacion' => 'Responsable reasignado',
            'cancelacion' => 'Orden cancelada',
            'reapertura' => 'Orden reabierta',
            'actuacion_manual' => 'Actuacion registrada',
            'evidencia' => 'Evidencia registrada',
        ];
    }

    public function query(string $userId, array $buildingIds, ?string $eventType): ?Builder
    {
        $visible = $this->visibleBuildingIds($userId, $buildingIds);
        if ($visible === []) {
            return null;
        }

        return DB::table($this->table('bitacora_orden_operativa').' as event')
            ->whereIn('event.edificio_id', $visible)
            ->when($eventType !== null, static fn (Builder $query) => $query->where('event.tipo', $eventType))
            ->selectRaw("event.id, event.edificio_id, 'operaciones' AS fuente, event.tipo, event.actor_tipo, event.actor_user_id, 'orden_operativa' AS recurso_tipo, event.orden_operativa_id AS recurso_id, event.created_at");
    }

    protected function permission(): PermisoEdificio
    {
        return PermisoEdificio::OPERACIONES_VER;
    }
}
