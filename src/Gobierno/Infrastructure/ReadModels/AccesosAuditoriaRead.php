<?php

namespace Src\Gobierno\Infrastructure\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final class AccesosAuditoriaRead extends FuenteAuditoriaRead
{
    public function code(): string
    {
        return 'accesos';
    }

    public function label(): string
    {
        return 'Accesos y permisos';
    }

    public function eventTypes(): array
    {
        return [
            'migracion' => 'Migracion de acceso',
            'membresia_creada' => 'Membresia creada',
            'invitacion_creada' => 'Invitacion creada',
            'invitacion_aceptada' => 'Invitacion aceptada',
            'invitacion_revocada' => 'Invitacion revocada',
            'roles_actualizados' => 'Roles actualizados',
            'membresia_revocada' => 'Membresia revocada',
        ];
    }

    public function query(string $userId, array $buildingIds, ?string $eventType): ?Builder
    {
        $visible = $this->visibleBuildingIds($userId, $buildingIds);
        if ($visible === []) {
            return null;
        }

        return DB::table($this->table('eventos_acceso_edificio').' as event')
            ->whereIn('event.edificio_id', $visible)
            ->when($eventType !== null, static fn (Builder $query) => $query->where('event.tipo', $eventType))
            ->selectRaw("event.id, event.edificio_id, 'accesos' AS fuente, event.tipo, CASE WHEN event.actor_user_id IS NULL THEN 'sistema' ELSE 'usuario' END AS actor_tipo, event.actor_user_id, CASE WHEN event.usuario_afectado_id IS NOT NULL THEN 'usuario' WHEN event.invitacion_id IS NOT NULL THEN 'invitacion' ELSE 'acceso' END AS recurso_tipo, COALESCE(event.usuario_afectado_id, event.invitacion_id, event.id) AS recurso_id, event.created_at");
    }

    protected function permission(): PermisoEdificio
    {
        return PermisoEdificio::MIEMBROS_VER;
    }
}
