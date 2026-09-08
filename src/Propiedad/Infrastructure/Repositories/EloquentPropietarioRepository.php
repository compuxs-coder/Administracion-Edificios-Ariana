<?php

namespace Src\Propiedad\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoPropietario;
use Src\Propiedad\Domain\Enums\EstadoTitularidad;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;

final class EloquentPropietarioRepository implements PropietarioRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_VER);
        $query = PropietarioEloquentModel::query()
            ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($buildingIds))
            ->withCount(['titularidades as propiedades_actuales_count' => static fn (Builder $query) => $query
                ->where('estado', 'activa')
                ->whereIn('edificio_id', $buildingIds)])
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $query) => $query
                    ->whereRaw('LOWER(identificacion) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(nombres, ?)) LIKE ?', ['', $term])
                    ->orWhereRaw('LOWER(COALESCE(apellidos, ?)) LIKE ?', ['', $term])
                    ->orWhereRaw("LOWER(COALESCE(nombres, '') || ' ' || COALESCE(apellidos, '')) LIKE ?", [$term])
                    ->orWhereRaw('LOWER(COALESCE(razon_social, ?)) LIKE ?', ['', $term]));
            })
            ->when($filters['tipo_persona'] ?? null, static fn (Builder $query, string $tipo) => $query->where('tipo_persona', $tipo))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $estado) => $query->where('estado', $estado))
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $edificioId) => $query
                ->whereHas('edificios', static fn (Builder $query) => $query
                    ->whereKey($edificioId)
                    ->whereKey($buildingIds)))
            ->orderByRaw('COALESCE(razon_social, apellidos, nombres)')
            ->orderBy('identificacion');

        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 15),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );

        return [
            'items' => $paginator->getCollection()
                ->map(fn (PropietarioEloquentModel $model): array => $this->serialize($model, $userId))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function findForUser(string $userId, string $propietarioId): ?array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_VER);
        $propietario = PropietarioEloquentModel::query()
            ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($buildingIds))
            ->withCount(['titularidades as propiedades_actuales_count' => static fn (Builder $query) => $query
                ->where('estado', EstadoTitularidad::ACTIVA->value)
                ->whereIn('edificio_id', $buildingIds)])
            ->with(['titularidades' => static fn ($query) => $query
                ->whereIn('edificio_id', $buildingIds)
                ->with(['edificio', 'departamento.piso.torre'])
                ->orderByDesc('fecha_inicio')])
            ->find($propietarioId);

        if ($propietario === null) {
            return null;
        }

        $result = $this->serialize($propietario, $userId);
        $result['propiedadesActuales'] = $propietario->titularidades
            ->where('estado', EstadoTitularidad::ACTIVA)
            ->map(fn (DepartamentoPropietarioEloquentModel $model): array => $this->serializeProperty($model))
            ->values()
            ->all();
        $result['historialPropiedades'] = $propietario->titularidades
            ->where('estado', EstadoTitularidad::FINALIZADA)
            ->map(fn (DepartamentoPropietarioEloquentModel $model): array => $this->serializeProperty($model))
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

        return PropietarioEloquentModel::query()
            ->where('estado', EstadoPropietario::ACTIVO->value)
            ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($buildingIds))
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
                $residentIsVisible = $tercero->residente()
                    ->whereHas('edificios', static fn (Builder $query) => $query->whereKey($edificioId))
                    ->exists();
                if ($tercero->propietario()->exists()
                    || $tercero->tipo_persona->value !== $identity['tipo_persona']
                    || ! $residentIsVisible) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'Ya existe una identidad con este tipo e identificación.',
                    ]);
                }
                if ($this->ownerIdentityData($tercero) !== $identity) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'Los datos deben coincidir con la identidad del residente existente.',
                    ]);
                }
            } else {
                $tercero = new TerceroEloquentModel();
                try {
                    $tercero->fill($identity)->save();
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'Ya existe una identidad con este tipo e identificación.',
                    ]);
                }
            }

            $propietario = new PropietarioEloquentModel();
            $propietario->estado = EstadoPropietario::ACTIVO;
            $propietario->tercero_id = $tercero->id;

            try {
                $propietario->fill([
                    ...$this->ownerIdentityData($tercero),
                    'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'identificacion' => 'Ya existe un propietario con este tipo e identificación.',
                ]);
            }

            $propietario->edificios()->attach($edificio->id);

            return ['id' => $propietario->id];
        });
    }

    public function update(string $userId, string $propietarioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $propietarioId, $data): array {
            $propietario = PropietarioEloquentModel::query()
                ->lockForUpdate()
                ->findOrFail($propietarioId);
            $this->assertUserCanManage($userId, $propietario);
            $tercero = TerceroEloquentModel::query()->lockForUpdate()->findOrFail($propietario->tercero_id);
            $identity = $this->identityData($data);
            if ($identity['tipo_persona'] !== TipoPersona::PERSONA_NATURAL->value
                && $tercero->residente()->exists()) {
                throw ValidationException::withMessages([
                    'tipo_persona' => 'Una identidad con perfil de residente debe permanecer como persona natural.',
                ]);
            }
            $conflict = TerceroEloquentModel::query()
                ->where('tipo_identificacion', $identity['tipo_identificacion'])
                ->where('identificacion', $identity['identificacion'])
                ->whereKeyNot($tercero->id)
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages([
                    'identificacion' => 'Ya existe una identidad con este tipo e identificación.',
                ]);
            }

            try {
                $tercero->fill($identity)->save();
                $propietario->fill([
                    ...$identity,
                    'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'identificacion' => 'Ya existe un propietario con este tipo e identificación.',
                ]);
            }

            return ['id' => $propietario->id];
        });
    }

    public function changeStatus(
        string $userId,
        string $propietarioId,
        EstadoPropietario $estado,
    ): void
    {
        DB::transaction(function () use ($userId, $propietarioId, $estado): void {
            $propietario = PropietarioEloquentModel::query()
                ->lockForUpdate()
                ->findOrFail($propietarioId);
            $this->assertUserCanManage($userId, $propietario);

            if ($estado === EstadoPropietario::INACTIVO
                && $propietario->titularidades()->where('estado', 'activa')->exists()) {
                throw ValidationException::withMessages([
                    'estado' => 'Finalice primero todas las propiedades activas del propietario.',
                ]);
            }

            $propietario->update(['estado' => $estado]);
        });
    }

    /** @return array<string, mixed> */
    private function persistenceData(array $data): array
    {
        $natural = $data['tipo_persona'] === TipoPersona::PERSONA_NATURAL->value;

        return [
            'tipo_persona' => $data['tipo_persona'],
            'nombres' => $natural ? trim($data['nombres']) : null,
            'apellidos' => $natural ? trim($data['apellidos']) : null,
            'razon_social' => $natural ? null : trim($data['razon_social']),
            'tipo_identificacion' => $data['tipo_identificacion'],
            'identificacion' => mb_strtoupper(trim($data['identificacion'])),
            'telefono' => $this->nullableTrim($data['telefono'] ?? null),
            'celular' => $this->nullableTrim($data['celular'] ?? null),
            'correo' => $this->nullableLower($data['correo'] ?? null),
            'direccion' => $this->nullableTrim($data['direccion'] ?? null),
            'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function identityData(array $data): array
    {
        $identity = $this->persistenceData($data);
        unset($identity['observaciones']);

        return $identity;
    }

    /** @return array<string, mixed> */
    private function ownerIdentityData(TerceroEloquentModel $tercero): array
    {
        return [
            'tipo_persona' => $tercero->tipo_persona->value,
            'nombres' => $tercero->nombres,
            'apellidos' => $tercero->apellidos,
            'razon_social' => $tercero->razon_social,
            'tipo_identificacion' => $tercero->tipo_identificacion->value,
            'identificacion' => $tercero->identificacion,
            'telefono' => $tercero->telefono,
            'celular' => $tercero->celular,
            'correo' => $tercero->correo,
            'direccion' => $tercero->direccion,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(PropietarioEloquentModel $model, ?string $userId = null): array
    {
        return [
            'id' => $model->id,
            'terceroId' => $model->tercero_id,
            'tipoPersona' => $model->tipo_persona->value,
            'nombres' => $model->nombres,
            'apellidos' => $model->apellidos,
            'razonSocial' => $model->razon_social,
            'nombre' => $this->displayName($model),
            'tipoIdentificacion' => $model->tipo_identificacion->value,
            'identificacion' => $model->identificacion,
            'telefono' => $model->telefono,
            'celular' => $model->celular,
            'correo' => $model->correo,
            'direccion' => $model->direccion,
            'estado' => $model->estado->value,
            'observaciones' => $model->observaciones,
            'propiedadesActualesCount' => (int) ($model->propiedades_actuales_count ?? 0),
            'puedeGestionar' => $userId === null ? null : $this->userCanManage($userId, $model),
            'createdAt' => $model->created_at?->toIso8601String(),
            'updatedAt' => $model->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeProperty(DepartamentoPropietarioEloquentModel $model): array
    {
        return [
            'id' => $model->id,
            'edificioId' => $model->edificio_id,
            'edificio' => $model->edificio->nombre,
            'departamentoId' => $model->departamento_id,
            'departamento' => $model->departamento->codigo,
            'torre' => $model->departamento->piso->torre->nombre,
            'piso' => $model->departamento->piso->nombre ?: $model->departamento->piso->numero,
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

    private function assertUserCanManage(string $userId, PropietarioEloquentModel $propietario): void
    {
        if (! $this->userCanManage($userId, $propietario)) {
            abort(403);
        }
    }

    private function userCanManage(string $userId, PropietarioEloquentModel $propietario): bool
    {
        $buildingIds = $propietario->edificios()->get()->modelKeys();
        $residente = $propietario->tercero?->residente()->first();
        if ($residente !== null) {
            $buildingIds = array_values(array_unique([...$buildingIds, ...$residente->edificios()->get()->modelKeys()]));
        }

        $manageableIds = $this->access->buildingIds($userId, PermisoEdificio::PROPIEDAD_GESTIONAR);

        return $buildingIds !== [] && array_diff($buildingIds, $manageableIds) === [];
    }
}
