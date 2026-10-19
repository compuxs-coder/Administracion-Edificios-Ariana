<?php

namespace Src\Comunicacion\Infrastructure\Repositories;

use Illuminate\Support\Facades\DB;
use Src\Comunicacion\Domain\Contracts\DestinatariosComunicacionReadInterface;

final class EloquentDestinatariosComunicacionRead implements DestinatariosComunicacionReadInterface
{
    public function resolve(
        string $buildingId,
        string $localDate,
        bool $includeOwners,
        bool $includeResidents,
    ): array {
        $recipients = [];

        if ($includeOwners) {
            $owners = DB::table($this->table('departamento_propietarios').' as ownership')
                ->join($this->table('propietarios').' as owner', 'owner.id', '=', 'ownership.propietario_id')
                ->join($this->table('terceros').' as third_party', 'third_party.id', '=', 'owner.tercero_id')
                ->join($this->table('departamentos').' as department', function ($join): void {
                    $join->on('department.id', '=', 'ownership.departamento_id')
                        ->on('department.edificio_id', '=', 'ownership.edificio_id');
                })
                ->where('ownership.edificio_id', $buildingId)
                ->where('department.estado', 'activo')
                ->where('ownership.estado', 'activa')
                ->where('owner.estado', 'activo')
                ->whereDate('ownership.fecha_inicio', '<=', $localDate)
                ->where(static fn ($query) => $query->whereNull('ownership.fecha_fin')->orWhereDate('ownership.fecha_fin', '>', $localDate))
                ->orderBy('third_party.id')
                ->orderBy('department.codigo')
                ->get($this->columns('owner.tercero_id'));
            foreach ($owners as $owner) {
                $this->merge($recipients, $owner, true, false);
            }
        }

        if ($includeResidents) {
            $residents = DB::table($this->table('departamento_residentes').' as occupancy')
                ->join($this->table('residentes').' as resident', 'resident.id', '=', 'occupancy.residente_id')
                ->join($this->table('terceros').' as third_party', 'third_party.id', '=', 'resident.tercero_id')
                ->join($this->table('departamentos').' as department', function ($join): void {
                    $join->on('department.id', '=', 'occupancy.departamento_id')
                        ->on('department.edificio_id', '=', 'occupancy.edificio_id');
                })
                ->where('occupancy.edificio_id', $buildingId)
                ->where('department.estado', 'activo')
                ->where('occupancy.estado', 'activa')
                ->where('resident.estado', 'activo')
                ->whereDate('occupancy.fecha_inicio', '<=', $localDate)
                ->where(static fn ($query) => $query->whereNull('occupancy.fecha_fin')->orWhereDate('occupancy.fecha_fin', '>', $localDate))
                ->orderBy('third_party.id')
                ->orderBy('department.codigo')
                ->get($this->columns('resident.tercero_id'));
            foreach ($residents as $resident) {
                $this->merge($recipients, $resident, false, true);
            }
        }

        return array_values(array_map(static function (array $recipient): array {
            $recipient['departamentos'] = array_values(array_unique($recipient['departamentos']));
            sort($recipient['departamentos']);

            return $recipient;
        }, $recipients));
    }

    /** @return list<string> */
    private function columns(string $thirdPartyId): array
    {
        return [
            $thirdPartyId.' as tercero_id', 'third_party.tipo_persona', 'third_party.nombres',
            'third_party.apellidos', 'third_party.razon_social', 'third_party.correo',
            'department.codigo as departamento_codigo',
        ];
    }

    /** @param array<string, array<string, mixed>> $recipients */
    private function merge(array &$recipients, object $source, bool $owner, bool $resident): void
    {
        $id = (string) $source->tercero_id;
        if (! isset($recipients[$id])) {
            $recipients[$id] = [
                'terceroId' => $id,
                'nombre' => $source->tipo_persona === 'persona_juridica'
                    ? trim((string) $source->razon_social)
                    : trim((string) $source->nombres.' '.(string) $source->apellidos),
                'email' => $source->correo === null ? null : trim((string) $source->correo),
                'esPropietario' => false,
                'esResidente' => false,
                'departamentos' => [],
            ];
        }
        $recipients[$id]['esPropietario'] = $recipients[$id]['esPropietario'] || $owner;
        $recipients[$id]['esResidente'] = $recipients[$id]['esResidente'] || $resident;
        $recipients[$id]['departamentos'][] = (string) $source->departamento_codigo;
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
}
