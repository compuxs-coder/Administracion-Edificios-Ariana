<?php

namespace Src\Edificio\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\BodegaEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoBodegaEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoParqueaderoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\ParqueaderoEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;
use Src\Propiedad\Domain\Enums\EstadoOcupacion;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;

final class EloquentDepartamentoRepository implements DepartamentoRepositoryInterface
{
    public function paginateForUser(string $userId, array $filters): array
    {
        $query = DepartamentoEloquentModel::query()
            ->whereHas('edificio.usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->with([
                'edificio',
                'piso.torre',
                'asignacionesParqueaderos' => static fn ($query) => $query->whereNull('fecha_fin')->with('parqueadero'),
                'asignacionesBodegas' => static fn ($query) => $query->whereNull('fecha_fin')->with('bodega'),
            ])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['torre_id'] ?? null, static fn (Builder $query, string $id) => $query->whereHas('piso', static fn (Builder $query) => $query->where('torre_id', $id)))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $estado) => $query->where('estado', $estado))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $query) => $query
                    ->whereRaw('LOWER(codigo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$term]));
            })
            ->orderBy('codigo');

        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 15),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );

        return [
            'items' => $paginator->getCollection()->map(fn (DepartamentoEloquentModel $model): array => $this->serialize($model))->values()->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function findForEdificio(string $edificioId, string $departamentoId): ?array
    {
        $model = DepartamentoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with([
                'edificio',
                'piso.torre',
                'asignacionesParqueaderos' => static fn ($query) => $query->whereNull('fecha_fin')->with('parqueadero'),
                'asignacionesBodegas' => static fn ($query) => $query->whereNull('fecha_fin')->with('bodega'),
            ])
            ->find($departamentoId);

        return $model === null ? null : $this->serialize($model);
    }

    public function formOptionsForUser(string $userId): array
    {
        return EdificioEloquentModel::query()
            ->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->with([
                'torres' => static fn ($query) => $query->orderBy('nombre'),
                'torres.pisos' => static fn ($query) => $query->orderBy('orden'),
                'parqueaderos' => static fn ($query) => $query->where('estado', 'activo')->orderBy('codigo'),
                'parqueaderos.asignaciones' => static fn ($query) => $query->whereNull('fecha_fin')->with('departamento'),
                'bodegas' => static fn ($query) => $query->where('estado', 'activo')->orderBy('codigo'),
                'bodegas.asignaciones' => static fn ($query) => $query->whereNull('fecha_fin')->with('departamento'),
            ])
            ->orderBy('nombre')
            ->get()
            ->map(static fn (EdificioEloquentModel $edificio): array => [
                'id' => $edificio->id,
                'nombre' => $edificio->nombre,
                'estado' => $edificio->estado->value,
                'torres' => $edificio->torres->map(static fn (TorreEloquentModel $torre): array => [
                    'id' => $torre->id,
                    'nombre' => $torre->nombre,
                    'estado' => $torre->estado->value,
                    'pisos' => $torre->pisos->map(static fn (PisoEloquentModel $piso): array => [
                        'id' => $piso->id,
                        'numero' => $piso->numero,
                        'nombre' => $piso->nombre,
                        'estado' => $piso->estado->value,
                    ])->values()->all(),
                ])->values()->all(),
                'parqueaderos' => $edificio->parqueaderos->map(static fn (ParqueaderoEloquentModel $anexo): array => [
                    'id' => $anexo->id,
                    'codigo' => $anexo->codigo,
                    'departamentoId' => $anexo->asignaciones->first()?->departamento_id,
                ])->values()->all(),
                'bodegas' => $edificio->bodegas->map(static fn (BodegaEloquentModel $anexo): array => [
                    'id' => $anexo->id,
                    'codigo' => $anexo->codigo,
                    'departamentoId' => $anexo->asignaciones->first()?->departamento_id,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function create(
        string $edificioId,
        array $data,
        array $parqueaderoIds,
        array $bodegaIds,
    ): array {
        return DB::transaction(function () use ($edificioId, $data, $parqueaderoIds, $bodegaIds): array {
            $piso = $this->activePiso($edificioId, $data['piso_id']);
            $departamento = new DepartamentoEloquentModel();
            $departamento->edificio_id = $edificioId;
            $departamento->piso_id = $piso->id;
            $departamento->estado = EstadoEstructura::ACTIVO;
            $this->saveDepartamento($departamento, $data);
            $this->syncAssignments($departamento, $parqueaderoIds, $bodegaIds);

            return ['id' => $departamento->id];
        });
    }

    public function update(
        string $edificioId,
        string $departamentoId,
        array $data,
        array $parqueaderoIds,
        array $bodegaIds,
    ): array {
        return DB::transaction(function () use ($edificioId, $departamentoId, $data, $parqueaderoIds, $bodegaIds): array {
            EdificioEloquentModel::query()->lockForUpdate()->findOrFail($edificioId);
            $departamento = DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($departamentoId);

            if ($departamento->estado === EstadoEstructura::ACTIVO
                || $departamento->piso_id !== $data['piso_id']) {
                $departamento->piso_id = $this->activePiso($edificioId, $data['piso_id'])->id;
            }

            $this->saveDepartamento($departamento, $data);

            if ($departamento->estado === EstadoEstructura::INACTIVO
                && ($parqueaderoIds !== [] || $bodegaIds !== [])) {
                $messages = [];
                if ($parqueaderoIds !== []) {
                    $messages['parqueaderos'] = 'Un departamento inactivo no puede conservar parqueaderos asignados.';
                }
                if ($bodegaIds !== []) {
                    $messages['bodegas'] = 'Un departamento inactivo no puede conservar bodegas asignadas.';
                }

                throw ValidationException::withMessages($messages);
            }

            $this->syncAssignments($departamento, $parqueaderoIds, $bodegaIds);

            return ['id' => $departamento->id];
        });
    }

    public function changeStatus(
        string $edificioId,
        string $departamentoId,
        EstadoEstructura $estado,
    ): void {
        DB::transaction(function () use ($edificioId, $departamentoId, $estado): void {
            EdificioEloquentModel::query()->lockForUpdate()->findOrFail($edificioId);
            $departamento = DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($departamentoId);

            if ($estado === EstadoEstructura::ACTIVO) {
                $this->activePiso($edificioId, $departamento->piso_id);
            } else {
                if (DepartamentoResidenteEloquentModel::query()
                    ->where('departamento_id', $departamento->id)
                    ->where('estado', EstadoOcupacion::ACTIVA->value)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'estado' => 'Finalice primero todas las ocupaciones activas del departamento.',
                    ]);
                }
                $now = now();
                $this->lockCurrentAnnexes($departamento);
                $departamento->asignacionesParqueaderos()->whereNull('fecha_fin')->update(['fecha_fin' => $now]);
                $departamento->asignacionesBodegas()->whereNull('fecha_fin')->update(['fecha_fin' => $now]);
            }

            $departamento->update(['estado' => $estado]);
        });
    }

    private function activePiso(string $edificioId, string $pisoId): PisoEloquentModel
    {
        $pisoReference = PisoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->find($pisoId);
        $edificio = EdificioEloquentModel::query()
            ->where('estado', 'activo')
            ->lockForUpdate()
            ->find($edificioId);
        $torre = $pisoReference === null ? null : TorreEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('estado', EstadoEstructura::ACTIVO->value)
            ->lockForUpdate()
            ->find($pisoReference->torre_id);
        $piso = PisoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('torre_id', $torre?->id)
            ->where('estado', EstadoEstructura::ACTIVO->value)
            ->lockForUpdate()
            ->find($pisoId);

        if ($edificio === null || $piso === null || $torre === null) {
            throw ValidationException::withMessages([
                'piso_id' => 'El piso seleccionado no pertenece al edificio o su estructura está inactiva.',
            ]);
        }

        return $piso;
    }

    /** @param list<string> $parqueaderoIds @param list<string> $bodegaIds */
    private function syncAssignments(
        DepartamentoEloquentModel $departamento,
        array $parqueaderoIds,
        array $bodegaIds,
    ): void {
        $this->syncAssignmentType(
            $departamento,
            array_values(array_unique($parqueaderoIds)),
            ParqueaderoEloquentModel::class,
            DepartamentoParqueaderoEloquentModel::class,
            'parqueadero_id',
            'parqueaderos',
        );
        $this->syncAssignmentType(
            $departamento,
            array_values(array_unique($bodegaIds)),
            BodegaEloquentModel::class,
            DepartamentoBodegaEloquentModel::class,
            'bodega_id',
            'bodegas',
        );
    }

    /**
     * @param list<string> $selectedIds
     * @param class-string<ParqueaderoEloquentModel|BodegaEloquentModel> $anexoModel
     * @param class-string<DepartamentoParqueaderoEloquentModel|DepartamentoBodegaEloquentModel> $assignmentModel
     */
    private function syncAssignmentType(
        DepartamentoEloquentModel $departamento,
        array $selectedIds,
        string $anexoModel,
        string $assignmentModel,
        string $foreignKey,
        string $field,
    ): void {
        $currentIdsSnapshot = $assignmentModel::query()
            ->where('departamento_id', $departamento->id)
            ->whereNull('fecha_fin')
            ->pluck($foreignKey)
            ->all();
        $lockIds = array_values(array_unique([...$selectedIds, ...$currentIdsSnapshot]));
        sort($lockIds);

        $lockedAnnexes = $anexoModel::query()
            ->where('edificio_id', $departamento->edificio_id)
            ->whereIn('id', $lockIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'estado']);
        $availableIds = $lockedAnnexes
            ->where('estado', EstadoEstructura::ACTIVO)
            ->whereIn('id', $selectedIds)
            ->pluck('id')
            ->all();

        if (count($availableIds) !== count($selectedIds)) {
            throw ValidationException::withMessages([$field => 'Uno o más anexos no pertenecen al edificio o están inactivos.']);
        }

        $openAssignments = $assignmentModel::query()
            ->where('edificio_id', $departamento->edificio_id)
            ->whereNull('fecha_fin')
            ->where(static fn (Builder $query) => $query
                ->where('departamento_id', $departamento->id)
                ->orWhereIn($foreignKey, $selectedIds))
            ->lockForUpdate()
            ->get();

        $conflict = $openAssignments->first(static fn ($assignment) => in_array($assignment->{$foreignKey}, $selectedIds, true)
            && $assignment->departamento_id !== $departamento->id);
        if ($conflict !== null) {
            throw ValidationException::withMessages([$field => 'Uno o más anexos ya están asignados a otro departamento.']);
        }

        $currentIds = $openAssignments
            ->where('departamento_id', $departamento->id)
            ->pluck($foreignKey)
            ->all();
        $removedIds = array_diff($currentIds, $selectedIds);
        $addedIds = array_diff($selectedIds, $currentIds);
        $now = now();

        if ($removedIds !== []) {
            $assignmentModel::query()
                ->where('departamento_id', $departamento->id)
                ->whereIn($foreignKey, $removedIds)
                ->whereNull('fecha_fin')
                ->update(['fecha_fin' => $now]);
        }

        foreach ($addedIds as $anexoId) {
            $assignment = new $assignmentModel();
            $assignment->edificio_id = $departamento->edificio_id;
            $assignment->departamento_id = $departamento->id;
            $assignment->{$foreignKey} = $anexoId;
            $assignment->fecha_inicio = $now;
            try {
                $assignment->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([$field => 'Uno o más anexos ya están asignados a otro departamento.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function persistenceData(array $data): array
    {
        $observaciones = trim((string) ($data['observaciones'] ?? ''));

        return [
            'codigo' => mb_strtoupper(trim($data['codigo'])),
            'nombre' => trim($data['nombre']),
            'alicuota' => $data['alicuota'],
            'observaciones' => $observaciones === '' ? null : $observaciones,
        ];
    }

    /** @param array<string, mixed> $data */
    private function saveDepartamento(DepartamentoEloquentModel $departamento, array $data): void
    {
        try {
            $departamento->fill($this->persistenceData($data))->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'codigo' => 'El código del departamento ya está registrado en este edificio.',
            ]);
        }
    }

    private function lockCurrentAnnexes(DepartamentoEloquentModel $departamento): void
    {
        $parqueaderoIds = $departamento->asignacionesParqueaderos()
            ->whereNull('fecha_fin')
            ->pluck('parqueadero_id')
            ->sort()
            ->values()
            ->all();
        $bodegaIds = $departamento->asignacionesBodegas()
            ->whereNull('fecha_fin')
            ->pluck('bodega_id')
            ->sort()
            ->values()
            ->all();

        ParqueaderoEloquentModel::query()->whereIn('id', $parqueaderoIds)->orderBy('id')->lockForUpdate()->get(['id']);
        BodegaEloquentModel::query()->whereIn('id', $bodegaIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** @return array<string, mixed> */
    private function serialize(DepartamentoEloquentModel $model): array
    {
        return [
            'id' => $model->id,
            'edificioId' => $model->edificio_id,
            'edificio' => $model->edificio->nombre,
            'torreId' => $model->piso->torre_id,
            'torre' => $model->piso->torre->nombre,
            'pisoId' => $model->piso_id,
            'piso' => $model->piso->nombre ?: $model->piso->numero,
            'codigo' => $model->codigo,
            'nombre' => $model->nombre,
            'alicuota' => $model->alicuota,
            'estado' => $model->estado->value,
            'observaciones' => $model->observaciones,
            'parqueaderos' => $model->asignacionesParqueaderos->map(static fn ($asignacion): array => [
                'id' => $asignacion->parqueadero->id,
                'codigo' => $asignacion->parqueadero->codigo,
            ])->values()->all(),
            'bodegas' => $model->asignacionesBodegas->map(static fn ($asignacion): array => [
                'id' => $asignacion->bodega->id,
                'codigo' => $asignacion->bodega->codigo,
            ])->values()->all(),
        ];
    }
}
