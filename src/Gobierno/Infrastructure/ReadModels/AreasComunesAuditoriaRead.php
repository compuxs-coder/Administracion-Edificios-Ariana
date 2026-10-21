<?php

namespace Src\Gobierno\Infrastructure\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final class AreasComunesAuditoriaRead extends FuenteAuditoriaRead
{
    private const RESERVATION_EVENTS = ['reserva_creada', 'reserva_aprobada', 'reserva_rechazada', 'reserva_cancelada'];

    public function code(): string
    {
        return 'areas_comunes';
    }

    public function label(): string
    {
        return 'Areas comunes';
    }

    public function eventTypes(): array
    {
        return [
            'espacio_creado' => 'Espacio creado',
            'espacio_actualizado' => 'Espacio actualizado',
            'espacio_activado' => 'Espacio activado',
            'espacio_inactivado' => 'Espacio inactivado',
            'regla_creada' => 'Regla creada',
            'reserva_creada' => 'Reserva creada',
            'reserva_aprobada' => 'Reserva aprobada',
            'reserva_rechazada' => 'Reserva rechazada',
            'reserva_cancelada' => 'Reserva cancelada',
        ];
    }

    public function visibleBuildingIds(string $userId, array $buildingIds): array
    {
        return array_values(array_unique([
            ...$this->authorizedBuildingIds($userId, $buildingIds, PermisoEdificio::AREAS_COMUNES_VER),
            ...$this->authorizedBuildingIds($userId, $buildingIds, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER),
        ]));
    }

    public function visibleEventTypes(string $userId, array $buildingIds): array
    {
        $eventTypes = $this->eventTypes();
        $visible = [];
        if ($this->authorizedBuildingIds($userId, $buildingIds, PermisoEdificio::AREAS_COMUNES_VER) !== []) {
            $visible = array_diff_key($eventTypes, array_flip(self::RESERVATION_EVENTS));
        }
        if ($this->authorizedBuildingIds($userId, $buildingIds, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER) !== []) {
            foreach (self::RESERVATION_EVENTS as $type) {
                $visible[$type] = $eventTypes[$type];
            }
        }

        return $visible;
    }

    public function query(string $userId, array $buildingIds, ?string $eventType): ?Builder
    {
        $areaBuildings = $this->authorizedBuildingIds($userId, $buildingIds, PermisoEdificio::AREAS_COMUNES_VER);
        $reservationBuildings = $this->authorizedBuildingIds($userId, $buildingIds, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER);
        if ($areaBuildings === [] && $reservationBuildings === []) {
            return null;
        }

        return DB::table($this->table('bitacora_areas_comunes').' as event')
            ->where(function (Builder $query) use ($areaBuildings, $reservationBuildings): void {
                if ($areaBuildings !== []) {
                    $query->where(static fn (Builder $events) => $events
                        ->whereIn('event.edificio_id', $areaBuildings)
                        ->whereNotIn('event.tipo', self::RESERVATION_EVENTS));
                }
                if ($reservationBuildings !== []) {
                    $method = $areaBuildings === [] ? 'where' : 'orWhere';
                    $query->{$method}(static fn (Builder $events) => $events
                        ->whereIn('event.edificio_id', $reservationBuildings)
                        ->whereIn('event.tipo', self::RESERVATION_EVENTS));
                }
            })
            ->when($eventType !== null, static fn (Builder $query) => $query->where('event.tipo', $eventType))
            ->selectRaw("event.id, event.edificio_id, 'areas_comunes' AS fuente, event.tipo, 'usuario' AS actor_tipo, event.actor_user_id, CASE WHEN event.reserva_id IS NOT NULL THEN 'reserva' WHEN event.regla_id IS NOT NULL THEN 'regla' ELSE 'espacio_comun' END AS recurso_tipo, COALESCE(event.reserva_id, event.regla_id, event.espacio_comun_id) AS recurso_id, event.created_at");
    }

    protected function permission(): PermisoEdificio
    {
        return PermisoEdificio::AREAS_COMUNES_VER;
    }
}
