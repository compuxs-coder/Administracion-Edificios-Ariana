<?php

namespace Src\Gobierno\Infrastructure\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final class MantenimientoAuditoriaRead extends FuenteAuditoriaRead
{
    public function code(): string
    {
        return 'mantenimiento';
    }

    public function label(): string
    {
        return 'Mantenimiento preventivo';
    }

    public function eventTypes(): array
    {
        return [
            'creacion' => 'Plan creado',
            'cambio_datos' => 'Plan actualizado',
            'activacion' => 'Plan activado',
            'pausa' => 'Plan pausado',
            'generacion' => 'Orden preventiva generada',
            'bloqueo' => 'Generacion bloqueada',
            'reintento' => 'Generacion reintentada',
            'omision' => 'Ocurrencia omitida',
        ];
    }

    public function query(string $userId, array $buildingIds, ?string $eventType): ?Builder
    {
        $visible = $this->visibleBuildingIds($userId, $buildingIds);
        if ($visible === []) {
            return null;
        }

        return DB::table($this->table('bitacora_plan_mantenimiento').' as event')
            ->whereIn('event.edificio_id', $visible)
            ->when($eventType !== null, static fn (Builder $query) => $query->where('event.tipo', $eventType))
            ->selectRaw("event.id, event.edificio_id, 'mantenimiento' AS fuente, event.tipo, event.actor_tipo, event.actor_user_id, 'plan_mantenimiento' AS recurso_tipo, event.plan_id AS recurso_id, event.created_at");
    }

    protected function permission(): PermisoEdificio
    {
        return PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER;
    }
}
