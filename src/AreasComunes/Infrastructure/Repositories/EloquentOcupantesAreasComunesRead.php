<?php

namespace Src\AreasComunes\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Src\AreasComunes\Domain\Contracts\OcupantesAreasComunesReadInterface;

final class EloquentOcupantesAreasComunesRead implements OcupantesAreasComunesReadInterface
{
    public function options(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        $localDates = DB::table($this->table('edificios'))->whereIn('id', $buildingIds)
            ->pluck('timezone', 'id')
            ->map(static fn (string $timezone): string => CarbonImmutable::now($timezone)->format('Y-m-d'));

        return $this->baseQuery()->whereIn('occupation.edificio_id', $buildingIds)
            ->where('occupation.estado', 'activa')->where('resident.estado', 'activo')->where('department.estado', 'activo')
            ->where(function ($query) use ($localDates): void {
                foreach ($localDates as $buildingId => $localDate) {
                    $query->orWhere(static fn ($buildingQuery) => $buildingQuery
                        ->where('occupation.edificio_id', $buildingId)
                        ->whereDate('occupation.fecha_inicio', '<=', $localDate));
                }
            })->whereNull('occupation.fecha_fin')
            ->orderBy('occupation.edificio_id')->orderBy('department.codigo')->orderBy('occupation.nombre_residente')
            ->get($this->columns())->map(fn (object $item): array => $this->serialize($item))->all();
    }

    public function eligibleOccupation(string $buildingId, string $occupationId, string $localDate, bool $lock = false): ?array
    {
        $query = $this->baseQuery()->where('occupation.edificio_id', $buildingId)->where('occupation.id', $occupationId)
            ->where('occupation.estado', 'activa')->where('resident.estado', 'activo')->where('department.estado', 'activo')
            ->whereDate('occupation.fecha_inicio', '<=', $localDate)
            ->where(static fn ($query) => $query->whereNull('occupation.fecha_fin')->orWhereDate('occupation.fecha_fin', '>', $localDate));
        if ($lock) {
            $query->lockForUpdate();
        }
        $item = $query->first($this->columns());

        return $item === null ? null : $this->serialize($item);
    }

    private function baseQuery()
    {
        return DB::table($this->table('departamento_residentes').' as occupation')
            ->join($this->table('residentes').' as resident', 'resident.id', '=', 'occupation.residente_id')
            ->join($this->table('departamentos').' as department', function ($join): void {
                $join->on('department.id', '=', 'occupation.departamento_id')->on('department.edificio_id', '=', 'occupation.edificio_id');
            });
    }

    /** @return list<string> */
    private function columns(): array
    {
        return ['occupation.id', 'occupation.edificio_id', 'occupation.departamento_id', 'occupation.residente_id', 'occupation.nombre_residente', 'occupation.tipo_identificacion_snapshot', 'occupation.identificacion_snapshot', 'occupation.tipo_ocupacion', 'occupation.fecha_inicio', 'occupation.fecha_fin', 'department.codigo as departamento_codigo', 'department.nombre as departamento_nombre'];
    }

    /** @return array<string, mixed> */
    private function serialize(object $item): array
    {
        return [
            'id' => $item->id,
            'edificioId' => $item->edificio_id,
            'departamentoId' => $item->departamento_id,
            'residenteId' => $item->residente_id,
            'nombre' => $item->nombre_residente,
            'tipoIdentificacion' => $item->tipo_identificacion_snapshot,
            'identificacion' => $item->identificacion_snapshot,
            'tipoOcupacion' => $item->tipo_ocupacion,
            'departamentoCodigo' => $item->departamento_codigo,
            'departamentoNombre' => $item->departamento_nombre,
            'fechaInicio' => $item->fecha_inicio,
            'fechaFin' => $item->fecha_fin,
        ];
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? config('database.application_schema').'.'.$name : $name;
    }
}
