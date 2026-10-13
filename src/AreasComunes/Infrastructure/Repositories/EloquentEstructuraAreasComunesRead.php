<?php

namespace Src\AreasComunes\Infrastructure\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\AreasComunes\Domain\Contracts\EstructuraAreasComunesReadInterface;

final class EloquentEstructuraAreasComunesRead implements EstructuraAreasComunesReadInterface
{
    public function options(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return ['edificios' => [], 'torres' => [], 'pisos' => []];
        }
        $elements = [];
        foreach (['torres' => ['codigo', 'nombre'], 'pisos' => ['numero', 'nombre']] as $name => $columns) {
            $elements[$name] = DB::table($this->table($name))->whereIn('edificio_id', $buildingIds)
                ->where('estado', 'activo')->orderBy('edificio_id')->orderBy($columns[0])
                ->get(['id', 'edificio_id', ...$columns])->map(static fn (object $item): array => (array) $item)->all();
        }

        return ['edificios' => $this->buildings($buildingIds), ...$elements];
    }

    public function buildings(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        return DB::table($this->table('edificios'))->whereIn('id', $buildingIds)->orderBy('nombre')
            ->get(['id', 'nombre', 'estado', 'timezone'])
            ->map(static fn (object $item): array => ['id' => $item->id, 'nombre' => $item->nombre, 'estado' => $item->estado, 'timezone' => $item->timezone])->all();
    }

    public function assertLocation(string $buildingId, array $data, array $current = [], bool $lock = false): void
    {
        $map = ['torre_id' => 'torres', 'piso_id' => 'pisos'];
        $selected = array_filter($map, static fn (string $table, string $field): bool => ($data[$field] ?? null) !== null, ARRAY_FILTER_USE_BOTH);
        if (count($selected) > 1) {
            throw ValidationException::withMessages(['ubicacion' => 'Seleccione como máximo una torre o un piso.']);
        }
        if ($selected === [] && trim((string) ($data['ubicacion_detalle'] ?? '')) === '') {
            throw ValidationException::withMessages(['ubicacion_detalle' => 'Indique una torre, un piso o un detalle de ubicación.']);
        }
        foreach ($selected as $field => $source) {
            $query = DB::table($this->table($source))->where('edificio_id', $buildingId)->where('id', $data[$field]);
            if (($current[$field] ?? null) !== $data[$field]) {
                $query->where('estado', 'activo');
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            if ($query->first(['id']) === null) {
                throw ValidationException::withMessages([$field => 'La ubicación no pertenece al edificio o está inactiva.']);
            }
        }
    }

    public function location(string $buildingId, array $data): array
    {
        foreach ([
            'torre' => ['field' => 'torre_id', 'table' => 'torres', 'columns' => ['codigo', 'nombre']],
            'piso' => ['field' => 'piso_id', 'table' => 'pisos', 'columns' => ['numero', 'nombre']],
        ] as $type => $definition) {
            $id = $data[$definition['field']] ?? null;
            if ($id === null) {
                continue;
            }
            $item = DB::table($this->table($definition['table']))->where('edificio_id', $buildingId)->where('id', $id)
                ->first(['id', ...$definition['columns']]);
            if ($item === null) {
                return [];
            }
            $label = $type === 'piso'
                ? 'Piso '.$item->numero.($item->nombre ? ' · '.$item->nombre : '')
                : $item->codigo.' · '.$item->nombre;

            return ['tipo' => $type, 'id' => $item->id, 'etiqueta' => $label];
        }

        return [];
    }

    public function memberNames(string $buildingId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table($this->table('edificio_usuario').' as membership')
            ->join($this->usersTable().' as users', 'users.id', '=', 'membership.user_id')
            ->where('membership.edificio_id', $buildingId)->whereIn('membership.user_id', $userIds)
            ->pluck('users.name', 'users.id')->all();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? config('database.application_schema').'.'.$name : $name;
    }

    private function usersTable(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'public.users' : 'users';
    }
}
