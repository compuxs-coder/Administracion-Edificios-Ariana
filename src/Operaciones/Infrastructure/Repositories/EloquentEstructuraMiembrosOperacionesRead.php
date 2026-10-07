<?php

namespace Src\Operaciones\Infrastructure\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Operaciones\Domain\Contracts\EstructuraMiembrosOperacionesReadInterface;

final class EloquentEstructuraMiembrosOperacionesRead implements EstructuraMiembrosOperacionesReadInterface
{
    public function buildings(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        return DB::table($this->table('edificios'))
            ->whereIn('id', $buildingIds)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'estado'])
            ->map(static fn (object $item): array => ['id' => $item->id, 'nombre' => $item->nombre, 'estado' => $item->estado])
            ->all();
    }

    public function options(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return ['edificios' => [], 'torres' => [], 'pisos' => [], 'departamentos' => [], 'parqueaderos' => [], 'bodegas' => []];
        }

        $edificios = $this->buildings($buildingIds);
        $elements = [];
        foreach ([
            'torres' => ['codigo', 'nombre'],
            'pisos' => ['numero', 'nombre'],
            'departamentos' => ['codigo', 'nombre'],
            'parqueaderos' => ['codigo', 'ubicacion'],
            'bodegas' => ['codigo', 'ubicacion'],
        ] as $name => $columns) {
            $elements[$name] = DB::table($this->table($name))
                ->whereIn('edificio_id', $buildingIds)
                ->where('estado', 'activo')
                ->orderBy('edificio_id')
                ->orderBy($columns[0])
                ->get(['id', 'edificio_id', ...$columns])
                ->map(static fn (object $item): array => (array) $item)
                ->all();
        }

        return ['edificios' => $edificios, ...$elements];
    }

    public function members(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        return DB::table($this->table('edificio_usuario').' as membership')
            ->join($this->usersTable().' as users', 'users.id', '=', 'membership.user_id')
            ->whereIn('membership.edificio_id', $buildingIds)
            ->whereNull('membership.revoked_at')
            ->orderBy('users.name')
            ->get(['membership.edificio_id', 'users.id', 'users.name', 'users.email'])
            ->map(static fn (object $member): array => [
                'edificioId' => $member->edificio_id,
                'id' => $member->id,
                'nombre' => $member->name,
                'correo' => $member->email,
            ])->all();
    }

    public function assertLocation(string $buildingId, array $location, array $currentLocation = [], bool $lockForUpdate = false): void
    {
        $map = [
            'torre_id' => 'torres',
            'piso_id' => 'pisos',
            'departamento_id' => 'departamentos',
            'parqueadero_id' => 'parqueaderos',
            'bodega_id' => 'bodegas',
        ];
        $selected = array_filter($map, static fn (string $table, string $field): bool => ($location[$field] ?? null) !== null, ARRAY_FILTER_USE_BOTH);
        if (count($selected) > 1) {
            throw ValidationException::withMessages(['ubicacion' => 'Seleccione como máximo un elemento estructural.']);
        }
        if ($selected === [] && trim((string) ($location['ubicacion_detalle'] ?? '')) === '') {
            throw ValidationException::withMessages(['ubicacion_detalle' => 'Indique un elemento estructural o un detalle de ubicación.']);
        }
        foreach ($selected as $field => $source) {
            $query = DB::table($this->table($source))
                ->where('edificio_id', $buildingId)
                ->where('id', $location[$field]);
            if (($currentLocation[$field] ?? null) !== $location[$field]) {
                $query->where('estado', 'activo');
            }
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            if ($query->first(['id']) === null) {
                throw ValidationException::withMessages([$field => 'El elemento estructural no pertenece al edificio.']);
            }
        }
    }

    public function location(string $buildingId, array $location): ?array
    {
        $definitions = [
            'torre' => ['field' => 'torre_id', 'table' => 'torres', 'columns' => ['codigo', 'nombre']],
            'piso' => ['field' => 'piso_id', 'table' => 'pisos', 'columns' => ['numero', 'nombre']],
            'departamento' => ['field' => 'departamento_id', 'table' => 'departamentos', 'columns' => ['codigo', 'nombre']],
            'parqueadero' => ['field' => 'parqueadero_id', 'table' => 'parqueaderos', 'columns' => ['codigo', 'ubicacion']],
            'bodega' => ['field' => 'bodega_id', 'table' => 'bodegas', 'columns' => ['codigo', 'ubicacion']],
        ];
        foreach ($definitions as $type => $definition) {
            $id = $location[$definition['field']] ?? null;
            if ($id === null) {
                continue;
            }
            $item = DB::table($this->table($definition['table']))
                ->where('edificio_id', $buildingId)
                ->where('id', $id)
                ->first(['id', ...$definition['columns']]);
            if ($item === null) {
                return null;
            }
            $label = match ($type) {
                'piso' => 'Piso '.$item->numero.($item->nombre ? ' · '.$item->nombre : ''),
                'parqueadero', 'bodega' => $item->codigo.($item->ubicacion ? ' · '.$item->ubicacion : ''),
                default => $item->codigo.' · '.$item->nombre,
            };

            return ['tipo' => $type, 'id' => $item->id, 'etiqueta' => $label];
        }

        return null;
    }

    public function activeMember(string $buildingId, string $userId, bool $lockForUpdate = false): ?array
    {
        $query = DB::table($this->table('edificio_usuario').' as membership')
            ->join($this->usersTable().' as users', 'users.id', '=', 'membership.user_id')
            ->where('membership.edificio_id', $buildingId)
            ->where('membership.user_id', $userId)
            ->whereNull('membership.revoked_at');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $member = $query->first(['users.id', 'users.name', 'users.email']);

        return $member === null ? null : [
            'usuarioId' => $member->id,
            'nombre' => $member->name,
            'correo' => $member->email,
        ];
    }

    public function memberNames(string $buildingId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table($this->table('edificio_usuario').' as membership')
            ->join($this->usersTable().' as users', 'users.id', '=', 'membership.user_id')
            ->where('membership.edificio_id', $buildingId)
            ->whereIn('membership.user_id', $userIds)
            ->pluck('users.name', 'users.id')
            ->all();
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
