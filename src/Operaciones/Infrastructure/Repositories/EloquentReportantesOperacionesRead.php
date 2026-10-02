<?php

namespace Src\Operaciones\Infrastructure\Repositories;

use Illuminate\Support\Facades\DB;
use Src\Operaciones\Domain\Contracts\ReportantesOperacionesReadInterface;

final class EloquentReportantesOperacionesRead implements ReportantesOperacionesReadInterface
{
    public function options(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        return $this->query()
            ->whereIn('association.edificio_id', $buildingIds)
            ->where('resident.estado', 'activo')
            ->orderBy('identity.apellidos')
            ->orderBy('identity.nombres')
            ->get($this->columns())
            ->map(function (object $resident): array {
                $serialized = $this->serialize($resident);

                return [
                    'edificioId' => $serialized['edificioId'],
                    'residenteId' => $serialized['residenteId'],
                    'nombre' => $serialized['nombre'],
                    'identificacion' => $serialized['identificacion'],
                ];
            })
            ->all();
    }

    public function activeSnapshot(string $buildingId, string $residentId, bool $lockForUpdate = false): ?array
    {
        $query = $this->query()
            ->where('association.edificio_id', $buildingId)
            ->where('resident.id', $residentId)
            ->where('resident.estado', 'activo');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $resident = $query->first($this->columns());

        return $resident === null ? null : $this->serialize($resident);
    }

    private function query()
    {
        return DB::table($this->table('residente_edificio').' as association')
            ->join($this->table('residentes').' as resident', 'resident.id', '=', 'association.residente_id')
            ->join($this->table('terceros').' as identity', 'identity.id', '=', 'resident.tercero_id');
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'association.edificio_id', 'resident.id', 'resident.tercero_id', 'identity.nombres',
            'identity.apellidos', 'identity.tipo_identificacion', 'identity.identificacion',
            'identity.telefono', 'identity.celular', 'identity.correo',
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(object $resident): array
    {
        return [
            'edificioId' => $resident->edificio_id,
            'residenteId' => $resident->id,
            'terceroId' => $resident->tercero_id,
            'nombre' => trim($resident->nombres.' '.$resident->apellidos),
            'tipoIdentificacion' => $resident->tipo_identificacion,
            'identificacion' => $resident->identificacion,
            'telefono' => $resident->telefono ?? $resident->celular,
            'correo' => $resident->correo,
        ];
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? config('database.application_schema').'.'.$name : $name;
    }
}
