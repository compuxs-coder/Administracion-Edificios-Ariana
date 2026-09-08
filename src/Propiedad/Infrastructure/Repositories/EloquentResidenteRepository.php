<?php

namespace Src\Propiedad\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoOcupacion;
use Src\Propiedad\Domain\Enums\EstadoResidente;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;

final class EloquentResidenteRepository implements ResidenteRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_VER);
        $query = ResidenteEloquentModel::query()
            ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($buildingIds))
            ->with('tercero')
            ->withCount(['ocupaciones as ocupaciones_actuales_count' => static fn (Builder $query) => $query
                ->where('estado', EstadoOcupacion::ACTIVA->value)
                ->whereIn('edificio_id', $buildingIds)])
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->whereHas('tercero', static fn (Builder $query) => $query
                    ->whereRaw('LOWER(identificacion) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(nombres, ?)) LIKE ?', ['', $term])
                    ->orWhereRaw('LOWER(COALESCE(apellidos, ?)) LIKE ?', ['', $term])
                    ->orWhereRaw("LOWER(COALESCE(nombres, '') || ' ' || COALESCE(apellidos, '')) LIKE ?", [$term]));
            })
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $estado) => $query->where('estado', $estado))
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $edificioId) => $query
                ->whereHas('edificios', static fn (Builder $query) => $query
                    ->whereKey($edificioId)
                    ->whereKey($buildingIds)))
            ->orderBy('created_at')
            ->orderBy('id');

        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()
                ->map(fn (ResidenteEloquentModel $model): array => $this->serialize($model, $userId))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function findForUser(string $userId, string $residenteId): ?array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_VER);
        $residente = ResidenteEloquentModel::query()
            ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($buildingIds))
            ->with('tercero')
            ->withCount(['ocupaciones as ocupaciones_actuales_count' => static fn (Builder $query) => $query
                ->where('estado', EstadoOcupacion::ACTIVA->value)
                ->whereIn('edificio_id', $buildingIds)])
            ->with(['ocupaciones' => static fn ($query) => $query
                ->whereIn('edificio_id', $buildingIds)
                ->with(['edificio', 'departamento.piso.torre'])
                ->orderByDesc('fecha_inicio')])
            ->find($residenteId);

        if ($residente === null) {
            return null;
        }

        $result = $this->serialize($residente, $userId);
        $result['ocupacionesActuales'] = $residente->ocupaciones
            ->where('estado', EstadoOcupacion::ACTIVA)
            ->map(fn (DepartamentoResidenteEloquentModel $model): array => $this->serializeOccupancy($model))
            ->values()
            ->all();
        $result['historialOcupaciones'] = $residente->ocupaciones
            ->where('estado', EstadoOcupacion::FINALIZADA)
            ->map(fn (DepartamentoResidenteEloquentModel $model): array => $this->serializeOccupancy($model))
            ->values()
            ->all();

        return $result;
    }

    public function buildingOptionsForUser(string $userId, bool $forManagement): array
    {
        return EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds(
                $userId,
                $forManagement ? PermisoEdificio::PROPIEDAD_GESTIONAR : PermisoEdificio::PROPIEDAD_VER,
            ))
            ->where('estado', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(static fn (EdificioEloquentModel $model): array => [
                'id' => $model->id,
                'nombre' => $model->nombre,
            ])
            ->values()
            ->all();
    }

    public function activeOptionsForUser(string $userId): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR);

        return ResidenteEloquentModel::query()
            ->where('estado', EstadoResidente::ACTIVO->value)
            ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($buildingIds))
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

    public function createForEdificio(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $edificio = EdificioEloquentModel::query()
                ->where('estado', 'activo')
                ->whereKey($this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR))
                ->lockForUpdate()
                ->findOrFail($edificioId);
            $identity = $this->identityData($data);
            $tercero = TerceroEloquentModel::query()
                ->where('tipo_identificacion', $identity['tipo_identificacion'])
                ->where('identificacion', $identity['identificacion'])
                ->lockForUpdate()
                ->first();

            if ($tercero !== null) {
                if ($tercero->tipo_persona !== TipoPersona::PERSONA_NATURAL
                    || ! $this->userCanManageTercero($userId, $tercero)) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'La identificación ya está registrada y no está disponible.',
                    ]);
                }
                if ($tercero->residente()->exists()) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'Ya existe un residente con este tipo e identificación.',
                    ]);
                }

                $tercero->fill($identity)->save();
                $tercero->propietario()->update($identity);
            } else {
                $tercero = new TerceroEloquentModel();
                try {
                    $tercero->fill($identity)->save();
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'La identificación ya está registrada y no está disponible.',
                    ]);
                }
            }

            $residente = new ResidenteEloquentModel();
            $residente->tercero_id = $tercero->id;
            $residente->estado = EstadoResidente::ACTIVO;
            $residente->observaciones = $this->nullableTrim($data['observaciones'] ?? null);
            $residente->save();
            $residente->edificios()->attach($edificio->id);

            return ['id' => $residente->id];
        });
    }

    public function update(string $userId, string $residenteId, array $data): array
    {
        return DB::transaction(function () use ($userId, $residenteId, $data): array {
            $residente = ResidenteEloquentModel::query()->lockForUpdate()->findOrFail($residenteId);
            $this->assertUserCanManage($userId, $residente);
            $tercero = TerceroEloquentModel::query()->lockForUpdate()->findOrFail($residente->tercero_id);
            $identity = $this->identityData($data);
            $conflict = TerceroEloquentModel::query()
                ->where('tipo_identificacion', $identity['tipo_identificacion'])
                ->where('identificacion', $identity['identificacion'])
                ->whereKeyNot($tercero->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'identificacion' => 'La identificación ya está registrada.',
                ]);
            }

            try {
                $tercero->fill($identity)->save();
                $tercero->propietario()->update($identity);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'identificacion' => 'La identificación ya está registrada.',
                ]);
            }

            $residente->update(['observaciones' => $this->nullableTrim($data['observaciones'] ?? null)]);

            return ['id' => $residente->id];
        });
    }

    public function changeStatus(string $userId, string $residenteId, EstadoResidente $estado): void
    {
        DB::transaction(function () use ($userId, $residenteId, $estado): void {
            $residente = ResidenteEloquentModel::query()->lockForUpdate()->findOrFail($residenteId);
            $this->assertUserCanManage($userId, $residente);

            if ($estado === EstadoResidente::INACTIVO
                && $residente->ocupaciones()->where('estado', EstadoOcupacion::ACTIVA->value)->exists()) {
                throw ValidationException::withMessages([
                    'estado' => 'Finalice primero todas las ocupaciones activas del residente.',
                ]);
            }

            $residente->update(['estado' => $estado]);
        });
    }

    /** @return array<string, mixed> */
    private function identityData(array $data): array
    {
        return [
            'tipo_persona' => TipoPersona::PERSONA_NATURAL->value,
            'nombres' => trim($data['nombres']),
            'apellidos' => trim($data['apellidos']),
            'razon_social' => null,
            'tipo_identificacion' => $data['tipo_identificacion'],
            'identificacion' => mb_strtoupper(trim($data['identificacion'])),
            'telefono' => $this->nullableTrim($data['telefono'] ?? null),
            'celular' => $this->nullableTrim($data['celular'] ?? null),
            'correo' => $this->nullableLower($data['correo'] ?? null),
            'direccion' => $this->nullableTrim($data['direccion'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(ResidenteEloquentModel $model, string $userId): array
    {
        return [
            'id' => $model->id,
            'terceroId' => $model->tercero_id,
            'nombres' => $model->tercero->nombres,
            'apellidos' => $model->tercero->apellidos,
            'nombre' => trim($model->tercero->nombres.' '.$model->tercero->apellidos),
            'tipoIdentificacion' => $model->tercero->tipo_identificacion->value,
            'identificacion' => $model->tercero->identificacion,
            'telefono' => $model->tercero->telefono,
            'celular' => $model->tercero->celular,
            'correo' => $model->tercero->correo,
            'direccion' => $model->tercero->direccion,
            'estado' => $model->estado->value,
            'observaciones' => $model->observaciones,
            'ocupacionesActualesCount' => (int) ($model->ocupaciones_actuales_count ?? 0),
            'puedeGestionar' => $this->userCanManage($userId, $model),
            'createdAt' => $model->created_at?->toIso8601String(),
            'updatedAt' => $model->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeOccupancy(DepartamentoResidenteEloquentModel $model): array
    {
        return [
            'id' => $model->id,
            'edificioId' => $model->edificio_id,
            'edificio' => $model->edificio->nombre,
            'departamentoId' => $model->departamento_id,
            'departamento' => $model->departamento->codigo,
            'torre' => $model->departamento->piso->torre->nombre,
            'piso' => $model->departamento->piso->nombre ?: $model->departamento->piso->numero,
            'tipoOcupacion' => $model->tipo_ocupacion->value,
            'fechaInicio' => $model->fecha_inicio->format('Y-m-d'),
            'fechaFin' => $model->fecha_fin?->format('Y-m-d'),
            'estado' => $model->estado->value,
            'observaciones' => $model->observaciones,
        ];
    }

    private function assertUserCanManage(string $userId, ResidenteEloquentModel $residente): void
    {
        if (! $this->userCanManage($userId, $residente)) {
            abort(403);
        }
    }

    private function userCanManage(string $userId, ResidenteEloquentModel $residente): bool
    {
        $tercero = $residente->relationLoaded('tercero')
            ? $residente->tercero
            : $residente->tercero()->first();

        return $tercero !== null && $this->userCanManageTercero($userId, $tercero);
    }

    private function userCanManageTercero(string $userId, TerceroEloquentModel $tercero): bool
    {
        $residente = $tercero->residente()->first();
        $propietario = $tercero->propietario()->first();
        $buildingIds = [];

        foreach ([$residente, $propietario] as $profile) {
            if ($profile === null) {
                continue;
            }
            $buildingIds = [...$buildingIds, ...$profile->edificios()->get()->modelKeys()];
        }

        $buildingIds = array_values(array_unique($buildingIds));
        $manageableIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR);

        return $buildingIds !== [] && array_diff($buildingIds, $manageableIds) === [];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableLower(mixed $value): ?string
    {
        $value = $this->nullableTrim($value);

        return $value === null ? null : mb_strtolower($value);
    }
}
