<?php

namespace Src\Gastos\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoProveedor;
use Src\Gastos\Domain\Enums\EstadoContratoProveedor;
use Src\Gastos\Infrastructure\Models\ContratoProveedorEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEdificioEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEloquentModel;
use Src\Propiedad\Application\Services\TerceroIdentityAuthorizationService;
use Src\Propiedad\Domain\Enums\TipoIdentificacion;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;

final class EloquentProveedorRepository implements ProveedorRepositoryInterface
{
    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly TerceroIdentityAuthorizationService $identityAccess,
    ) {}

    public function options(string $userId, PermisoEdificio $permission): array
    {
        $buildingIds = $this->access->buildingIds($userId, $permission);
        $buildings = EdificioEloquentModel::query()
            ->whereKey($buildingIds)
            ->where('estado', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(static fn (EdificioEloquentModel $building): array => [
                'id' => $building->id,
                'nombre' => $building->nombre,
            ])
            ->values()
            ->all();
        $suppliers = ProveedorEdificioEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->with('proveedor.tercero')
            ->orderBy('edificio_id')
            ->orderBy('nombre_comercial')
            ->get()
            ->map(static function (ProveedorEdificioEloquentModel $association): array {
                $identity = $association->proveedor->tercero;
                $name = $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
                    ? $identity->razon_social
                    : trim($identity->nombres.' '.$identity->apellidos);

                return [
                    'id' => $association->proveedor_id,
                    'edificioId' => $association->edificio_id,
                    'nombre' => $association->nombre_comercial ?: $name,
                    'identificacion' => $identity->identificacion,
                    'estado' => $association->estado->value,
                ];
            })
            ->values()
            ->all();
        $contracts = ContratoProveedorEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->where('estado', EstadoContratoProveedor::REGISTRADO->value)
            ->orderBy('referencia')
            ->get(['id', 'edificio_id', 'proveedor_id', 'referencia', 'objeto', 'estado'])
            ->map(static fn (ContratoProveedorEloquentModel $contract): array => [
                'id' => $contract->id,
                'edificioId' => $contract->edificio_id,
                'proveedorId' => $contract->proveedor_id,
                'referencia' => $contract->referencia,
                'objeto' => $contract->objeto,
                'estado' => $contract->estado->value,
            ])
            ->values()
            ->all();

        return ['edificios' => $buildings, 'proveedores' => $suppliers, 'contratos' => $contracts];
    }

    public function paginateForUser(string $userId, array $filters): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::GASTOS_VER);
        $query = ProveedorEdificioEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->with(['edificio', 'proveedor.tercero'])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $query) => $query
                    ->whereRaw('LOWER(COALESCE(nombre_comercial, ?)) LIKE ?', ['', $term])
                    ->orWhereHas('proveedor.tercero', static fn (Builder $query) => $query
                        ->whereRaw('LOWER(identificacion) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(razon_social, ?)) LIKE ?', ['', $term])
                        ->orWhereRaw("LOWER(COALESCE(nombres, '') || ' ' || COALESCE(apellidos, '')) LIKE ?", [$term])));
            })
            ->orderBy('edificio_id')
            ->orderBy('nombre_comercial');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (ProveedorEdificioEloquentModel $association): array => $this->serialize($association))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $proveedorId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_VER);
        $association = ProveedorEdificioEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('proveedor_id', $proveedorId)
            ->with(['edificio', 'proveedor.tercero'])
            ->firstOrFail();

        return $this->serialize($association);
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $identity = $this->identityData($data);
            $tercero = TerceroEloquentModel::query()
                ->where('tipo_identificacion', $identity['tipo_identificacion'])
                ->where('identificacion', $identity['identificacion'])
                ->lockForUpdate()
                ->first();

            if ($tercero !== null) {
                if ($this->identityDataFromModel($tercero) !== $identity) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'Los datos enviados no coinciden exactamente con la identidad existente.',
                    ]);
                }
                if (! $this->identityAccess->allows($userId, $tercero)) {
                    throw ValidationException::withMessages([
                        'identificacion' => 'La identidad existe en un contexto que el usuario no puede gestionar.',
                    ]);
                }
                $proveedor = $tercero->proveedor()->lockForUpdate()->first();
            } else {
                $tercero = new TerceroEloquentModel();
                try {
                    $tercero->fill($identity)->save();
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['identificacion' => 'La identidad ya está registrada.']);
                }
                $proveedor = null;
            }

            if ($proveedor === null) {
                $proveedor = new ProveedorEloquentModel();
                $proveedor->tercero_id = $tercero->id;
                $proveedor->save();
            }
            if ($proveedor->edificios()->whereKey($edificioId)->exists()) {
                throw ValidationException::withMessages(['identificacion' => 'El proveedor ya está asociado al edificio.']);
            }

            $association = new ProveedorEdificioEloquentModel();
            $association->fill([
                'edificio_id' => $edificioId,
                'proveedor_id' => $proveedor->id,
                'estado' => EstadoProveedor::ACTIVO,
                ...$this->commercialData($data),
            ])->save();

            return ['id' => $proveedor->id];
        });
    }

    public function update(string $userId, string $edificioId, string $proveedorId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $proveedorId, $data): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $association = $this->lockedAssociation($edificioId, $proveedorId);
            $association->fill($this->commercialData($data))->save();
        });
    }

    public function changeStatus(string $userId, string $edificioId, string $proveedorId, EstadoProveedor $estado): void
    {
        DB::transaction(function () use ($userId, $edificioId, $proveedorId, $estado): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $association = $this->lockedAssociation($edificioId, $proveedorId);
            $association->estado = $estado;
            $association->save();
        });
    }

    private function authorizedBuilding(string $userId, string $buildingId, PermisoEdificio $permission, bool $lock = false): EdificioEloquentModel
    {
        $query = EdificioEloquentModel::query()->whereKey($this->access->buildingIds($userId, $permission));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($buildingId);
    }

    private function lockedAssociation(string $buildingId, string $supplierId): ProveedorEdificioEloquentModel
    {
        return ProveedorEdificioEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $supplierId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function identityData(array $data): array
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
        ];
    }

    /** @return array<string, mixed> */
    private function identityDataFromModel(TerceroEloquentModel $tercero): array
    {
        return [
            'tipo_persona' => $tercero->tipo_persona instanceof TipoPersona ? $tercero->tipo_persona->value : $tercero->tipo_persona,
            'nombres' => $tercero->nombres,
            'apellidos' => $tercero->apellidos,
            'razon_social' => $tercero->razon_social,
            'tipo_identificacion' => $tercero->tipo_identificacion instanceof TipoIdentificacion ? $tercero->tipo_identificacion->value : $tercero->tipo_identificacion,
            'identificacion' => $tercero->identificacion,
            'telefono' => $tercero->telefono,
            'celular' => $tercero->celular,
            'correo' => $tercero->correo,
            'direccion' => $tercero->direccion,
        ];
    }

    /** @return array<string, mixed> */
    private function commercialData(array $data): array
    {
        return [
            'nombre_comercial' => $this->nullableTrim($data['nombre_comercial'] ?? null),
            'contacto' => $this->nullableTrim($data['contacto'] ?? null),
            'telefono' => $this->nullableTrim($data['telefono_comercial'] ?? null),
            'correo' => $this->nullableLower($data['correo_comercial'] ?? null),
            'direccion' => $this->nullableTrim($data['direccion_comercial'] ?? null),
            'dias_credito' => isset($data['dias_credito']) ? (int) $data['dias_credito'] : null,
            'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(ProveedorEdificioEloquentModel $association): array
    {
        $supplier = $association->proveedor;
        $identity = $supplier->tercero;
        $name = $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
            ? $identity->razon_social
            : trim($identity->nombres.' '.$identity->apellidos);

        return [
            'id' => $supplier->id,
            'edificioId' => $association->edificio_id,
            'edificio' => $association->edificio->nombre,
            'terceroId' => $identity->id,
            'tipoPersona' => $identity->tipo_persona->value,
            'nombres' => $identity->nombres,
            'apellidos' => $identity->apellidos,
            'razonSocial' => $identity->razon_social,
            'nombre' => $name,
            'tipoIdentificacion' => $identity->tipo_identificacion->value,
            'identificacion' => $identity->identificacion,
            'telefonoIdentidad' => $identity->telefono,
            'celular' => $identity->celular,
            'correoIdentidad' => $identity->correo,
            'direccionIdentidad' => $identity->direccion,
            'estado' => $association->estado->value,
            'nombreComercial' => $association->nombre_comercial,
            'contacto' => $association->contacto,
            'telefono' => $association->telefono,
            'correo' => $association->correo,
            'direccion' => $association->direccion,
            'diasCredito' => $association->dias_credito,
            'observaciones' => $association->observaciones,
            'createdAt' => $association->created_at?->toIso8601String(),
            'updatedAt' => $association->updated_at?->toIso8601String(),
        ];
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
