<?php

namespace Src\Edificio\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Enums\TipoAnexo;
use Src\Edificio\Infrastructure\Models\BodegaEloquentModel;
use Src\Edificio\Infrastructure\Models\ParqueaderoEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;

final class EloquentEstructuraRepository implements EstructuraRepositoryInterface
{
    public function getForEdificio(string $edificioId): array
    {
        $torres = TorreEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['pisos' => static fn ($query) => $query
                ->withCount('departamentos')
                ->orderBy('orden')])
            ->orderByDesc('es_predeterminada')
            ->orderBy('nombre')
            ->get();

        $serializeAnexo = fn (Model $anexo): array => $this->serializeAnexo($anexo);

        $parqueaderos = ParqueaderoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['torre', 'asignaciones' => static fn ($query) => $query
                ->whereNull('fecha_fin')
                ->with('departamento')])
            ->orderBy('codigo')
            ->get()
            ->map($serializeAnexo)
            ->values()
            ->all();

        $bodegas = BodegaEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['torre', 'asignaciones' => static fn ($query) => $query
                ->whereNull('fecha_fin')
                ->with('departamento')])
            ->orderBy('codigo')
            ->get()
            ->map($serializeAnexo)
            ->values()
            ->all();

        return [
            'torres' => $torres->map(static fn (TorreEloquentModel $torre): array => [
                'id' => $torre->id,
                'codigo' => $torre->codigo,
                'nombre' => $torre->nombre,
                'descripcion' => $torre->descripcion,
                'esPredeterminada' => $torre->es_predeterminada,
                'estado' => $torre->estado->value,
                'pisos' => $torre->pisos->map(static fn (PisoEloquentModel $piso): array => [
                    'id' => $piso->id,
                    'torreId' => $piso->torre_id,
                    'numero' => $piso->numero,
                    'nombre' => $piso->nombre,
                    'orden' => $piso->orden,
                    'estado' => $piso->estado->value,
                    'departamentos' => $piso->departamentos_count,
                ])->values()->all(),
            ])->values()->all(),
            'parqueaderos' => $parqueaderos,
            'bodegas' => $bodegas,
            'resumen' => [
                'torres' => $torres->count(),
                'pisos' => $torres->sum(static fn (TorreEloquentModel $torre): int => $torre->pisos->count()),
                'departamentos' => $torres->sum(static fn (TorreEloquentModel $torre): int => $torre->pisos->sum('departamentos_count')),
                'parqueaderosDisponibles' => collect($parqueaderos)->where('estadoOperativo', 'disponible')->count(),
                'bodegasDisponibles' => collect($bodegas)->where('estadoOperativo', 'disponible')->count(),
            ],
        ];
    }

    public function saveTorre(string $edificioId, ?string $torreId, array $data): array
    {
        return DB::transaction(function () use ($edificioId, $torreId, $data): array {
            $torre = $torreId === null
                ? new TorreEloquentModel()
                : TorreEloquentModel::query()
                    ->where('edificio_id', $edificioId)
                    ->lockForUpdate()
                    ->findOrFail($torreId);

            if ($torreId === null) {
                $torre->edificio_id = $edificioId;
                $torre->es_predeterminada = false;
                $torre->estado = EstadoEstructura::ACTIVO;
            }

            try {
                $torre->fill([
                    'codigo' => mb_strtoupper(trim($data['codigo'])),
                    'nombre' => trim($data['nombre']),
                    'descripcion' => $this->nullableTrim($data['descripcion'] ?? null),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['codigo' => 'El código de la torre ya está registrado en este edificio.']);
            }

            return ['id' => $torre->id];
        });
    }

    public function savePiso(string $edificioId, ?string $pisoId, array $data): array
    {
        return DB::transaction(function () use ($edificioId, $pisoId, $data): array {
            $torre = TorreEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoEstructura::ACTIVO->value)
                ->lockForUpdate()
                ->find($data['torre_id']);

            if ($torre === null) {
                throw ValidationException::withMessages([
                    'torre_id' => 'La torre seleccionada no pertenece al edificio o está inactiva.',
                ]);
            }

            $piso = $pisoId === null
                ? new PisoEloquentModel()
                : PisoEloquentModel::query()
                    ->where('edificio_id', $edificioId)
                    ->lockForUpdate()
                    ->findOrFail($pisoId);

            if ($pisoId === null) {
                $piso->edificio_id = $edificioId;
                $piso->estado = EstadoEstructura::ACTIVO;
            }

            $piso->torre_id = $torre->id;

            try {
                $piso->fill([
                    'numero' => mb_strtoupper(trim($data['numero'])),
                    'nombre' => $this->nullableTrim($data['nombre'] ?? null),
                    'orden' => (int) $data['orden'],
                ])->save();
            } catch (UniqueConstraintViolationException $exception) {
                $field = str_contains(strtolower($exception->getMessage()), 'orden') ? 'orden' : 'numero';
                throw ValidationException::withMessages([$field => 'El valor ya está registrado en esta torre.']);
            }

            return ['id' => $piso->id];
        });
    }

    public function saveAnexo(
        TipoAnexo $tipo,
        string $edificioId,
        ?string $anexoId,
        array $data,
    ): array {
        return DB::transaction(function () use ($tipo, $edificioId, $anexoId, $data): array {
            $modelClass = $this->anexoModel($tipo);
            $anexo = $anexoId === null
                ? new $modelClass()
                : $modelClass::query()
                    ->where('edificio_id', $edificioId)
                    ->lockForUpdate()
                    ->findOrFail($anexoId);

            $torreId = $data['torre_id'] ?? null;
            if ($torreId !== null && TorreEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoEstructura::ACTIVO->value)
                ->lockForUpdate()
                ->find($torreId) === null) {
                throw ValidationException::withMessages([
                    'torre_id' => 'La torre seleccionada no pertenece al edificio o está inactiva.',
                ]);
            }

            if ($anexoId === null) {
                $anexo->edificio_id = $edificioId;
                $anexo->estado = EstadoEstructura::ACTIVO;
            }

            $anexo->torre_id = $torreId;

            try {
                $anexo->fill([
                    'codigo' => mb_strtoupper(trim($data['codigo'])),
                    'ubicacion' => $this->nullableTrim($data['ubicacion'] ?? null),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['codigo' => 'El código ya está registrado en este edificio.']);
            }

            return ['id' => $anexo->id];
        });
    }

    public function changeTorreStatus(
        string $edificioId,
        string $torreId,
        EstadoEstructura $estado,
    ): void {
        DB::transaction(function () use ($edificioId, $torreId, $estado): void {
            $torre = TorreEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($torreId);

            if ($estado === EstadoEstructura::INACTIVO) {
                if ($torre->es_predeterminada) {
                    throw ValidationException::withMessages(['estado' => 'La torre principal no puede inactivarse.']);
                }

                if ($torre->pisos()->where('estado', EstadoEstructura::ACTIVO->value)->exists()) {
                    throw ValidationException::withMessages(['estado' => 'Inactiva primero los pisos activos de la torre.']);
                }

                if (ParqueaderoEloquentModel::query()->where('torre_id', $torre->id)->where('estado', 'activo')->exists()
                    || BodegaEloquentModel::query()->where('torre_id', $torre->id)->where('estado', 'activo')->exists()) {
                    throw ValidationException::withMessages(['estado' => 'Inactiva o reubica primero los anexos activos de la torre.']);
                }
            }

            $torre->update(['estado' => $estado]);
        });
    }

    public function changePisoStatus(
        string $edificioId,
        string $pisoId,
        EstadoEstructura $estado,
    ): void {
        DB::transaction(function () use ($edificioId, $pisoId, $estado): void {
            $pisoReference = PisoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->findOrFail($pisoId);

            if ($estado === EstadoEstructura::ACTIVO
                && TorreEloquentModel::query()
                    ->where('edificio_id', $edificioId)
                    ->where('estado', EstadoEstructura::ACTIVO->value)
                    ->lockForUpdate()
                    ->find($pisoReference->torre_id) === null) {
                throw ValidationException::withMessages(['estado' => 'Activa primero la torre del piso.']);
            }

            $piso = PisoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($pisoId);

            if ($estado === EstadoEstructura::INACTIVO
                && $piso->departamentos()->where('estado', EstadoEstructura::ACTIVO->value)->exists()) {
                throw ValidationException::withMessages(['estado' => 'Inactiva primero los departamentos activos del piso.']);
            }

            $piso->update(['estado' => $estado]);
        });
    }

    public function changeAnexoStatus(
        TipoAnexo $tipo,
        string $edificioId,
        string $anexoId,
        EstadoEstructura $estado,
    ): void {
        DB::transaction(function () use ($tipo, $edificioId, $anexoId, $estado): void {
            $modelClass = $this->anexoModel($tipo);
            $anexo = $modelClass::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($anexoId);

            if ($estado === EstadoEstructura::INACTIVO
                && $anexo->asignaciones()->whereNull('fecha_fin')->exists()) {
                throw ValidationException::withMessages(['estado' => 'El anexo está asignado; retíralo del departamento antes de inactivarlo.']);
            }

            if ($estado === EstadoEstructura::ACTIVO && $anexo->torre_id !== null
                && TorreEloquentModel::query()
                    ->where('edificio_id', $edificioId)
                    ->where('estado', EstadoEstructura::ACTIVO->value)
                    ->lockForUpdate()
                    ->find($anexo->torre_id) === null) {
                throw ValidationException::withMessages(['estado' => 'Activa primero la torre asociada al anexo.']);
            }

            $anexo->update(['estado' => $estado]);
        });
    }

    private function serializeAnexo(Model $anexo): array
    {
        $asignacion = $anexo->asignaciones->first();

        return [
            'id' => $anexo->id,
            'torreId' => $anexo->torre_id,
            'torre' => $anexo->torre?->nombre,
            'codigo' => $anexo->codigo,
            'ubicacion' => $anexo->ubicacion,
            'estado' => $anexo->estado->value,
            'estadoOperativo' => $anexo->estado === EstadoEstructura::INACTIVO
                ? 'inactivo'
                : ($asignacion === null ? 'disponible' : 'asignado'),
            'departamento' => $asignacion?->departamento === null ? null : [
                'id' => $asignacion->departamento->id,
                'codigo' => $asignacion->departamento->codigo,
            ],
        ];
    }

    /** @return class-string<ParqueaderoEloquentModel|BodegaEloquentModel> */
    private function anexoModel(TipoAnexo $tipo): string
    {
        return $tipo === TipoAnexo::PARQUEADERO
            ? ParqueaderoEloquentModel::class
            : BodegaEloquentModel::class;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
