<?php

namespace Src\Propiedad\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;
use Src\Propiedad\Domain\Enums\EstadoPropietario;
use Src\Propiedad\Domain\Enums\EstadoTitularidad;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class EloquentPropietarioRepository implements PropietarioRepositoryInterface
{
    public function paginateForUser(string $userId, array $filters): array
    {
        $query = PropietarioEloquentModel::query()
            ->whereHas('edificios.usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->withCount(['titularidades as propiedades_actuales_count' => static fn (Builder $query) => $query
                ->where('estado', 'activa')
                ->whereHas('edificio.usuarios', static fn (Builder $query) => $query->whereKey($userId))])
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
                    ->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId))))
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
        $propietario = PropietarioEloquentModel::query()
            ->whereHas('edificios.usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->withCount(['titularidades as propiedades_actuales_count' => static fn (Builder $query) => $query
                ->where('estado', EstadoTitularidad::ACTIVA->value)
                ->whereHas('edificio.usuarios', static fn (Builder $query) => $query->whereKey($userId))])
            ->with(['titularidades' => static fn ($query) => $query
                ->whereHas('edificio.usuarios', static fn (Builder $query) => $query->whereKey($userId))
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

    public function buildingOptionsForUser(string $userId): array
    {
        return EdificioEloquentModel::query()
            ->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId))
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

    public function createForEdificio(string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($edificioId, $data): array {
            $edificio = EdificioEloquentModel::query()
                ->where('estado', 'activo')
                ->lockForUpdate()
                ->findOrFail($edificioId);
            $propietario = new PropietarioEloquentModel();
            $propietario->estado = EstadoPropietario::ACTIVO;

            try {
                $propietario->fill($this->persistenceData($data))->save();
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

            try {
                $propietario->fill($this->persistenceData($data))->save();
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
    private function serialize(PropietarioEloquentModel $model, ?string $userId = null): array
    {
        return [
            'id' => $model->id,
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
        return $propietario->edificios()
            ->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->exists()
            && ! $propietario->edificios()
                ->whereDoesntHave('usuarios', static fn (Builder $query) => $query->whereKey($userId))
                ->exists();
    }
}
