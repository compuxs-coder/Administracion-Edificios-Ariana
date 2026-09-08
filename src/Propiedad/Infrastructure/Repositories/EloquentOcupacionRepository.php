<?php

namespace Src\Propiedad\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Contracts\OcupacionRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoOcupacion;
use Src\Propiedad\Domain\Enums\EstadoResidente;
use Src\Propiedad\Domain\Enums\TipoOcupacion;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;

final class EloquentOcupacionRepository implements OcupacionRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function getForDepartamento(string $userId, string $edificioId, string $departamentoId): array
    {
        $this->authorizedEdificio($userId, $edificioId);
        $departamento = DepartamentoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->findOrFail($departamentoId);
        $ocupaciones = DepartamentoResidenteEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('departamento_id', $departamento->id)
            ->orderByDesc('fecha_inicio')
            ->get();

        return [
            'actuales' => $ocupaciones->where('estado', EstadoOcupacion::ACTIVA)
                ->map(fn (DepartamentoResidenteEloquentModel $model): array => $this->serialize($model))
                ->values()
                ->all(),
            'historial' => $ocupaciones->where('estado', EstadoOcupacion::FINALIZADA)
                ->map(fn (DepartamentoResidenteEloquentModel $model): array => $this->serialize($model))
                ->values()
                ->all(),
            'opciones' => $this->residentOptions($userId),
        ];
    }

    public function assign(string $userId, string $edificioId, string $departamentoId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $departamentoId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, true, true);
            $departamento = DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoEstructura::ACTIVO->value)
                ->lockForUpdate()
                ->find($departamentoId);
            if ($departamento === null) {
                throw ValidationException::withMessages([
                    'departamento_id' => 'El departamento no pertenece al edificio o está inactivo.',
                ]);
            }

            $residente = ResidenteEloquentModel::query()
                ->where('estado', EstadoResidente::ACTIVO->value)
                ->whereHas('edificios', fn (Builder $query) => $query->whereKey(
                    $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR),
                ))
                ->with('tercero')
                ->lockForUpdate()
                ->find($data['residente_id']);
            if ($residente === null) {
                throw ValidationException::withMessages([
                    'residente_id' => 'El residente no está activo o no es accesible.',
                ]);
            }

            $fechaInicio = CarbonImmutable::parse($data['fecha_inicio'])->startOfDay();
            if ($fechaInicio->isFuture()) {
                throw ValidationException::withMessages(['fecha_inicio' => 'La fecha de inicio no puede ser futura.']);
            }

            $this->assertNoOverlap($departamento->id, $residente->id, $fechaInicio);
            if ($data['tipo_ocupacion'] === TipoOcupacion::PROPIETARIO_OCUPANTE->value) {
                $this->assertOwnerAt($departamento->id, $residente->tercero_id, $fechaInicio);
            }

            $residente->edificios()->syncWithoutDetaching([$edificioId]);
            $ocupacion = new DepartamentoResidenteEloquentModel();
            $ocupacion->edificio_id = $edificioId;
            $ocupacion->departamento_id = $departamento->id;
            $ocupacion->residente_id = $residente->id;
            $ocupacion->fill([
                'nombre_residente' => trim($residente->tercero->nombres.' '.$residente->tercero->apellidos),
                'tipo_identificacion_snapshot' => $residente->tercero->tipo_identificacion->value,
                'identificacion_snapshot' => $residente->tercero->identificacion,
                'tipo_ocupacion' => $data['tipo_ocupacion'],
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => null,
                'estado' => EstadoOcupacion::ACTIVA,
                'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
            ]);

            try {
                $ocupacion->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'residente_id' => 'El residente ya tiene una ocupación activa en este departamento.',
                ]);
            }
        });
    }

    public function finalize(
        string $userId,
        string $edificioId,
        string $departamentoId,
        string $ocupacionId,
        array $data,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $departamentoId, $ocupacionId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, true);
            DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($departamentoId);
            $ocupacion = DepartamentoResidenteEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('departamento_id', $departamentoId)
                ->where('estado', EstadoOcupacion::ACTIVA->value)
                ->lockForUpdate()
                ->findOrFail($ocupacionId);
            $fechaFin = CarbonImmutable::parse($data['fecha_fin'])->startOfDay();

            if ($fechaFin->isFuture()) {
                throw ValidationException::withMessages(['fecha_fin' => 'La fecha final no puede ser futura.']);
            }
            if ($fechaFin->lessThanOrEqualTo($ocupacion->fecha_inicio)) {
                throw ValidationException::withMessages([
                    'fecha_fin' => 'La fecha final debe ser posterior a la fecha de inicio.',
                ]);
            }

            $ocupacion->fill([
                'fecha_fin' => $fechaFin,
                'estado' => EstadoOcupacion::FINALIZADA,
                'observaciones' => $this->nullableTrim($data['observaciones'] ?? null) ?? $ocupacion->observaciones,
            ])->save();
        });
    }

    private function authorizedEdificio(
        string $userId,
        string $edificioId,
        bool $lock = false,
        bool $active = false,
    ): EdificioEloquentModel {
        $query = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds(
                $userId,
                $lock ? PermisoEdificio::PROPIEDAD_GESTIONAR : PermisoEdificio::PROPIEDAD_VER,
            ));
        if ($active) {
            $query->where('estado', 'activo');
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    private function assertNoOverlap(
        string $departamentoId,
        string $residenteId,
        CarbonImmutable $fechaInicio,
    ): void {
        $overlap = DepartamentoResidenteEloquentModel::query()
            ->where('departamento_id', $departamentoId)
            ->where('residente_id', $residenteId)
            ->where(static fn (Builder $query) => $query
                ->whereNull('fecha_fin')
                ->orWhereDate('fecha_fin', '>', $fechaInicio->format('Y-m-d')))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'residente_id' => 'La nueva ocupación se superpone con el historial del residente.',
            ]);
        }
    }

    private function assertOwnerAt(
        string $departamentoId,
        string $terceroId,
        CarbonImmutable $fechaInicio,
    ): void {
        $ownsAtStart = DepartamentoPropietarioEloquentModel::query()
            ->where('departamento_id', $departamentoId)
            ->whereDate('fecha_inicio', '<=', $fechaInicio->format('Y-m-d'))
            ->where(static fn (Builder $query) => $query
                ->whereNull('fecha_fin')
                ->orWhereDate('fecha_fin', '>', $fechaInicio->format('Y-m-d')))
            ->whereHas('propietario', static fn (Builder $query) => $query->where('tercero_id', $terceroId))
            ->exists();

        if (! $ownsAtStart) {
            throw ValidationException::withMessages([
                'tipo_ocupacion' => 'El residente no tiene una titularidad vigente al inicio de la ocupación.',
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function residentOptions(string $userId): array
    {
        return ResidenteEloquentModel::query()
            ->where('estado', EstadoResidente::ACTIVO->value)
            ->whereHas('edificios', fn (Builder $query) => $query->whereKey(
                $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR),
            ))
            ->with('tercero')
            ->orderBy('created_at')
            ->get()
            ->map(static fn (ResidenteEloquentModel $model): array => [
                'id' => $model->id,
                'nombre' => trim($model->tercero->nombres.' '.$model->tercero->apellidos),
                'identificacion' => $model->tercero->identificacion,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(DepartamentoResidenteEloquentModel $model): array
    {
        return [
            'id' => $model->id,
            'residenteId' => $model->residente_id,
            'nombre' => $model->nombre_residente,
            'identificacion' => $model->identificacion_snapshot,
            'tipoOcupacion' => $model->tipo_ocupacion->value,
            'fechaInicio' => $model->fecha_inicio->format('Y-m-d'),
            'fechaFin' => $model->fecha_fin?->format('Y-m-d'),
            'estado' => $model->estado->value,
            'observaciones' => $model->observaciones,
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
