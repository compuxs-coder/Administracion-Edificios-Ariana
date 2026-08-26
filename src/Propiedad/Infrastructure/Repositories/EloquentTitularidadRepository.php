<?php

namespace Src\Propiedad\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Contracts\TitularidadRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoPropietario;
use Src\Propiedad\Domain\Enums\EstadoTitularidad;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class EloquentTitularidadRepository implements TitularidadRepositoryInterface
{
    public function getForDepartamento(
        string $userId,
        string $edificioId,
        string $departamentoId,
    ): array {
        $this->authorizedEdificio($userId, $edificioId);
        $departamento = DepartamentoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->findOrFail($departamentoId);
        $titularidades = DepartamentoPropietarioEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('departamento_id', $departamento->id)
            ->with('propietario')
            ->orderByDesc('fecha_inicio')
            ->get();

        return [
            'actuales' => $titularidades->where('estado', EstadoTitularidad::ACTIVA)
                ->map(fn (DepartamentoPropietarioEloquentModel $model): array => $this->serialize($model))
                ->values()
                ->all(),
            'historial' => $titularidades->where('estado', EstadoTitularidad::FINALIZADA)
                ->map(fn (DepartamentoPropietarioEloquentModel $model): array => $this->serialize($model))
                ->values()
                ->all(),
            'opciones' => $this->ownerOptions($userId),
            'participacionActual' => $this->formatPercentageUnits(
                $titularidades->where('estado', EstadoTitularidad::ACTIVA)
                    ->sum(fn (DepartamentoPropietarioEloquentModel $model): int => $this->percentageUnits($model->porcentaje)),
            ),
        ];
    }

    public function assign(
        string $userId,
        string $edificioId,
        string $departamentoId,
        array $data,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $departamentoId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, true);
            $departamento = $this->activeDepartamento($edificioId, $departamentoId);
            $propietario = $this->visibleActiveOwners($userId, [$data['propietario_id']])->first();
            if ($propietario === null) {
                throw ValidationException::withMessages([
                    'propietario_id' => 'El propietario no está activo o no es accesible.',
                ]);
            }
            $fechaInicio = CarbonImmutable::parse($data['fecha_inicio'])->startOfDay();

            $this->assertNoOverlap($departamento->id, $propietario->id, $fechaInicio);
            $this->assertTemporalCapacity(
                $departamento->id,
                $fechaInicio,
                null,
                [$data['porcentaje']],
            );

            $propietario->edificios()->syncWithoutDetaching([$edificioId]);

            $titularidad = new DepartamentoPropietarioEloquentModel();
            $titularidad->edificio_id = $edificioId;
            $titularidad->departamento_id = $departamento->id;
            $titularidad->propietario_id = $propietario->id;
            $titularidad->fill([
                'porcentaje' => $data['porcentaje'],
                ...$this->ownerSnapshot($propietario),
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => null,
                'estado' => EstadoTitularidad::ACTIVA,
                'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
            ]);

            try {
                $titularidad->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'propietario_id' => 'El propietario ya tiene una titularidad activa en este departamento.',
                ]);
            }
        });
    }

    public function finalize(
        string $userId,
        string $edificioId,
        string $departamentoId,
        string $titularidadId,
        array $data,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $departamentoId, $titularidadId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, true);
            DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($departamentoId);
            $titularidad = DepartamentoPropietarioEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('departamento_id', $departamentoId)
                ->where('estado', EstadoTitularidad::ACTIVA->value)
                ->lockForUpdate()
                ->findOrFail($titularidadId);
            $fechaFin = CarbonImmutable::parse($data['fecha_fin'])->startOfDay();

            if ($fechaFin->lessThanOrEqualTo($titularidad->fecha_inicio)) {
                throw ValidationException::withMessages([
                    'fecha_fin' => 'La fecha final debe ser posterior a la fecha de inicio.',
                ]);
            }

            $titularidad->fill([
                'fecha_fin' => $fechaFin,
                'estado' => EstadoTitularidad::FINALIZADA,
                'observaciones' => $this->nullableTrim($data['observaciones'] ?? null) ?? $titularidad->observaciones,
            ])->save();
        });
    }

    public function transfer(
        string $userId,
        string $edificioId,
        string $departamentoId,
        array $data,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $departamentoId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, true);
            $departamento = $this->activeDepartamento($edificioId, $departamentoId);
            $fecha = CarbonImmutable::parse($data['fecha_transferencia'])->startOfDay();
            $nuevos = $this->normalizedTransferOwners($data);
            $ids = $nuevos->pluck('propietario_id')->all();
            $owners = $this->visibleActiveOwners($userId, $ids);

            if ($owners->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'propietarios' => 'Uno o más propietarios no están activos o no son accesibles.',
                ]);
            }

            $actuales = DepartamentoPropietarioEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('departamento_id', $departamento->id)
                ->where('estado', EstadoTitularidad::ACTIVA->value)
                ->orderBy('propietario_id')
                ->lockForUpdate()
                ->get();

            if ($actuales->isEmpty()) {
                throw ValidationException::withMessages([
                    'propietarios' => 'El departamento no tiene propietarios activos para transferir.',
                ]);
            }

            if ($actuales->contains(static fn (DepartamentoPropietarioEloquentModel $model): bool => $fecha->lessThanOrEqualTo($model->fecha_inicio))) {
                throw ValidationException::withMessages([
                    'fecha_transferencia' => 'La transferencia debe ser posterior al inicio de todas las titularidades actuales.',
                ]);
            }

            $closingIds = $actuales->pluck('id')->all();
            $this->assertTemporalCapacity(
                $departamento->id,
                $fecha,
                null,
                $nuevos->pluck('porcentaje')->all(),
                $closingIds,
            );

            foreach ($nuevos as $nuevo) {
                $this->assertNoOverlap(
                    $departamento->id,
                    $nuevo['propietario_id'],
                    $fecha,
                    $closingIds,
                );
            }

            $observaciones = $this->nullableTrim($data['observaciones'] ?? null);
            foreach ($actuales as $actual) {
                $actual->fill([
                    'fecha_fin' => $fecha,
                    'estado' => EstadoTitularidad::FINALIZADA,
                    'observaciones' => $this->transferNote($actual->observaciones, $observaciones),
                ])->save();
            }

            foreach ($owners as $owner) {
                $owner->edificios()->syncWithoutDetaching([$edificioId]);
            }

            foreach ($nuevos as $nuevo) {
                $owner = $owners->firstWhere('id', $nuevo['propietario_id']);
                $titularidad = new DepartamentoPropietarioEloquentModel();
                $titularidad->edificio_id = $edificioId;
                $titularidad->departamento_id = $departamento->id;
                $titularidad->propietario_id = $nuevo['propietario_id'];
                $titularidad->fill([
                    'porcentaje' => $nuevo['porcentaje'],
                    ...$this->ownerSnapshot($owner),
                    'fecha_inicio' => $fecha,
                    'fecha_fin' => null,
                    'estado' => EstadoTitularidad::ACTIVA,
                    'observaciones' => $observaciones,
                ])->save();
            }

        });
    }

    private function authorizedEdificio(
        string $userId,
        string $edificioId,
        bool $lock = false,
    ): EdificioEloquentModel {
        $query = EdificioEloquentModel::query()
            ->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    private function activeDepartamento(string $edificioId, string $departamentoId): DepartamentoEloquentModel
    {
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

        return $departamento;
    }

    /** @param list<string> $ids @return Collection<int, PropietarioEloquentModel> */
    private function visibleActiveOwners(string $userId, array $ids): Collection
    {
        return PropietarioEloquentModel::query()
            ->whereIn('id', $ids)
            ->where('estado', EstadoPropietario::ACTIVO->value)
            ->whereHas('edificios.usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param list<string> $excludeIds */
    private function assertNoOverlap(
        string $departamentoId,
        string $propietarioId,
        CarbonImmutable $fechaInicio,
        array $excludeIds = [],
    ): void {
        $overlap = DepartamentoPropietarioEloquentModel::query()
            ->where('departamento_id', $departamentoId)
            ->where('propietario_id', $propietarioId)
            ->when($excludeIds !== [], static fn (Builder $query) => $query->whereNotIn('id', $excludeIds))
            ->where(static fn (Builder $query) => $query
                ->whereNull('fecha_fin')
                ->orWhereDate('fecha_fin', '>', $fechaInicio->format('Y-m-d')))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'propietario_id' => 'La nueva titularidad se superpone con el historial del propietario.',
            ]);
        }
    }

    /**
     * @param list<mixed> $percentages
     * @param list<string> $excludeIds
     */
    private function assertTemporalCapacity(
        string $departamentoId,
        CarbonImmutable $fechaInicio,
        ?CarbonImmutable $fechaFin,
        array $percentages,
        array $excludeIds = [],
    ): void {
        $requested = collect($percentages)
            ->sum(fn (mixed $percentage): int => $this->percentageUnits($percentage));
        $existing = DepartamentoPropietarioEloquentModel::query()
            ->where('departamento_id', $departamentoId)
            ->when($excludeIds !== [], static fn (Builder $query) => $query->whereNotIn('id', $excludeIds))
            ->whereDate('fecha_inicio', '<', $fechaFin?->format('Y-m-d') ?? '9999-12-31')
            ->where(static fn (Builder $query) => $query
                ->whereNull('fecha_fin')
                ->orWhereDate('fecha_fin', '>', $fechaInicio->format('Y-m-d')))
            ->get();
        $boundaries = $existing
            ->map(static fn (DepartamentoPropietarioEloquentModel $model): CarbonImmutable => $model->fecha_inicio->greaterThan($fechaInicio)
                ? $model->fecha_inicio
                : $fechaInicio)
            ->push($fechaInicio)
            ->unique(static fn (CarbonImmutable $date): string => $date->format('Y-m-d'));

        foreach ($boundaries as $boundary) {
            if ($fechaFin !== null && ! $boundary->lessThan($fechaFin)) {
                continue;
            }

            $existingUnits = $existing
                ->filter(static fn (DepartamentoPropietarioEloquentModel $model): bool => ! $model->fecha_inicio->greaterThan($boundary)
                    && ($model->fecha_fin === null || $model->fecha_fin->greaterThan($boundary)))
                ->sum(fn (DepartamentoPropietarioEloquentModel $model): int => $this->percentageUnits($model->porcentaje));

            if ($existingUnits + $requested > 100_000_000) {
                throw ValidationException::withMessages([
                    'porcentaje' => 'La participación supera el 100% durante la vigencia indicada.',
                ]);
            }
        }
    }

    /** @return Collection<int, array{propietario_id: string, porcentaje: mixed}> */
    private function normalizedTransferOwners(array $data): Collection
    {
        $rawOwners = $data['propietarios'] ?? null;
        if (! is_array($rawOwners) || $rawOwners === []) {
            throw ValidationException::withMessages([
                'propietarios' => 'La transferencia requiere al menos un propietario.',
            ]);
        }

        $owners = collect($rawOwners)->map(static function (mixed $owner): array {
            if (! is_array($owner)) {
                throw ValidationException::withMessages([
                    'propietarios' => 'Cada propietario de la transferencia debe tener una estructura válida.',
                ]);
            }

            $id = Str::lower((string) ($owner['propietario_id'] ?? ''));
            $percentage = $owner['porcentaje'] ?? null;
            if (! Str::isUuid($id) || ! is_numeric($percentage)
                || (float) $percentage <= 0 || (float) $percentage > 100) {
                throw ValidationException::withMessages([
                    'propietarios' => 'La transferencia contiene un propietario o participación inválidos.',
                ]);
            }

            return ['propietario_id' => $id, 'porcentaje' => $percentage];
        })->sortBy('propietario_id')->values();

        if ($owners->pluck('propietario_id')->unique()->count() !== $owners->count()) {
            throw ValidationException::withMessages([
                'propietarios' => 'Un propietario no puede repetirse en la transferencia.',
            ]);
        }

        $units = $owners->sum(fn (array $owner): int => $this->percentageUnits($owner['porcentaje']));
        if ($units !== 100_000_000) {
            throw ValidationException::withMessages([
                'propietarios' => 'La transferencia debe distribuir exactamente el 100%.',
            ]);
        }

        return $owners;
    }

    /** @return list<array<string, mixed>> */
    private function ownerOptions(string $userId): array
    {
        return PropietarioEloquentModel::query()
            ->where('estado', EstadoPropietario::ACTIVO->value)
            ->whereHas('edificios.usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->orderByRaw('COALESCE(razon_social, apellidos, nombres)')
            ->get()
            ->map(fn (PropietarioEloquentModel $model): array => [
                'id' => $model->id,
                'nombre' => $this->displayName($model),
                'identificacion' => $model->identificacion,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(DepartamentoPropietarioEloquentModel $model): array
    {
        return [
            'id' => $model->id,
            'propietarioId' => $model->propietario_id,
            'nombre' => $model->nombre_propietario,
            'identificacion' => $model->identificacion_snapshot,
            'porcentaje' => $model->porcentaje,
            'fechaInicio' => $model->fecha_inicio->format('Y-m-d'),
            'fechaFin' => $model->fecha_fin?->format('Y-m-d'),
            'estado' => $model->estado->value,
            'observaciones' => $model->observaciones,
        ];
    }

    private function displayName(PropietarioEloquentModel $model): string
    {
        return $model->tipo_persona === TipoPersona::PERSONA_JURIDICA
            ? (string) $model->razon_social
            : trim($model->nombres.' '.$model->apellidos);
    }

    private function percentageUnits(mixed $value): int
    {
        return (int) round((float) $value * 1_000_000);
    }

    private function formatPercentageUnits(int $units): string
    {
        return number_format($units / 1_000_000, 6, '.', '');
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function transferNote(?string $current, ?string $transfer): ?string
    {
        if ($transfer === null) {
            return $current;
        }

        return $current === null ? $transfer : $current."\n".$transfer;
    }

    /** @return array<string, string> */
    private function ownerSnapshot(PropietarioEloquentModel $owner): array
    {
        return [
            'nombre_propietario' => $this->displayName($owner),
            'tipo_identificacion_snapshot' => $owner->tipo_identificacion->value,
            'identificacion_snapshot' => $owner->identificacion,
        ];
    }
}
