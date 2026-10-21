<?php

namespace Src\Gobierno\Infrastructure\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Enums\PermisoEdificio;

final class ComunicacionAuditoriaRead extends FuenteAuditoriaRead
{
    public function code(): string
    {
        return 'comunicacion';
    }

    public function label(): string
    {
        return 'Comunicacion';
    }

    public function eventTypes(): array
    {
        return [
            'comunicado_creado' => 'Comunicado creado',
            'comunicado_actualizado' => 'Comunicado actualizado',
            'comunicado_publicado' => 'Comunicado publicado',
            'comunicado_archivado' => 'Comunicado archivado',
            'documento_agregado' => 'Documento agregado',
            'documento_retirado' => 'Documento retirado',
            'entrega_reintentada' => 'Entrega reintentada',
        ];
    }

    public function query(string $userId, array $buildingIds, ?string $eventType): ?Builder
    {
        $visible = $this->visibleBuildingIds($userId, $buildingIds);
        if ($visible === []) {
            return null;
        }

        return DB::table($this->table('bitacora_comunicacion').' as event')
            ->whereIn('event.edificio_id', $visible)
            ->when($eventType !== null, static fn (Builder $query) => $query->where('event.tipo', $eventType))
            ->selectRaw("event.id, event.edificio_id, 'comunicacion' AS fuente, event.tipo, 'usuario' AS actor_tipo, event.actor_user_id, 'comunicado' AS recurso_tipo, event.comunicado_id AS recurso_id, event.created_at");
    }

    protected function permission(): PermisoEdificio
    {
        return PermisoEdificio::COMUNICADOS_VER;
    }
}
