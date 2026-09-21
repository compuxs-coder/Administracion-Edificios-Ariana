<?php

namespace Src\Gastos\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoContratoProveedor;
use Src\Gastos\Domain\Enums\EstadoProveedor;
use Src\Gastos\Infrastructure\Models\ContratoProveedorEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoPersona;

final class EloquentContratoProveedorRepository implements ContratoProveedorRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = ContratoProveedorEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::GASTOS_VER))
            ->with(['edificio', 'proveedor.tercero'])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['proveedor_id'] ?? null, static fn (Builder $query, string $id) => $query->where('proveedor_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $query) => $query
                    ->whereRaw('LOWER(referencia) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(objeto) LIKE ?', [$term]));
            })
            ->orderByDesc('fecha_inicio')
            ->orderBy('referencia');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (ContratoProveedorEloquentModel $contract): array => $this->serialize($contract))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $contratoId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_VER);
        $contract = ContratoProveedorEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['edificio', 'proveedor.tercero'])
            ->findOrFail($contratoId);

        return $this->serialize($contract);
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $this->activeSupplier($edificioId, $data['proveedor_id'], true);
            $contract = new ContratoProveedorEloquentModel();
            $contract->fill([
                'edificio_id' => $edificioId,
                'proveedor_id' => $data['proveedor_id'],
                ...$this->persistenceData($data),
                'estado' => EstadoContratoProveedor::BORRADOR,
            ]);
            try {
                $contract->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['referencia' => 'Ya existe un contrato con esta referencia en el edificio.']);
            }

            return ['id' => $contract->id];
        });
    }

    public function update(string $userId, string $edificioId, string $contratoId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $contratoId, $data): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $contract = $this->lockedContract($edificioId, $contratoId);
            if ($contract->estado !== EstadoContratoProveedor::BORRADOR) {
                throw ValidationException::withMessages(['estado' => 'Sólo se pueden editar contratos en borrador.']);
            }
            $this->activeSupplier($edificioId, $data['proveedor_id'], true);
            $contract->proveedor_id = $data['proveedor_id'];
            $contract->fill($this->persistenceData($data));
            try {
                $contract->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['referencia' => 'Ya existe un contrato con esta referencia en el edificio.']);
            }
        });
    }

    public function register(string $userId, string $edificioId, string $contratoId): void
    {
        DB::transaction(function () use ($userId, $edificioId, $contratoId): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $contract = $this->lockedContract($edificioId, $contratoId);
            if ($contract->estado !== EstadoContratoProveedor::BORRADOR) {
                throw ValidationException::withMessages(['estado' => 'Sólo se puede registrar un contrato en borrador.']);
            }
            $supplier = $this->activeSupplier($edificioId, $contract->proveedor_id, true);
            $contract->fill([
                'estado' => EstadoContratoProveedor::REGISTRADO,
                'proveedor_snapshot' => $this->supplierSnapshot($supplier),
                'registrado_por' => $userId,
                'registrado_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    public function cancel(string $userId, string $edificioId, string $contratoId, string $reason): void
    {
        DB::transaction(function () use ($userId, $edificioId, $contratoId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_ANULAR, true);
            $contract = $this->lockedContract($edificioId, $contratoId);
            if ($contract->estado !== EstadoContratoProveedor::REGISTRADO) {
                throw ValidationException::withMessages(['estado' => 'Sólo se puede anular un contrato registrado.']);
            }
            $contract->fill([
                'estado' => EstadoContratoProveedor::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => CarbonImmutable::now(),
                'motivo_anulacion' => trim($reason),
            ])->save();
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

    private function activeSupplier(string $buildingId, string $supplierId, bool $lock): ProveedorEdificioEloquentModel
    {
        $query = ProveedorEdificioEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $supplierId)
            ->where('estado', EstadoProveedor::ACTIVO->value)
            ->with('proveedor.tercero');
        if ($lock) {
            $query->lockForUpdate();
        }

        $supplier = $query->first();
        if ($supplier === null) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor no pertenece al edificio o está inactivo.']);
        }

        return $supplier;
    }

    private function lockedContract(string $buildingId, string $contractId): ContratoProveedorEloquentModel
    {
        return ContratoProveedorEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->lockForUpdate()
            ->findOrFail($contractId);
    }

    /** @return array<string, mixed> */
    private function persistenceData(array $data): array
    {
        return [
            'referencia' => trim($data['referencia']),
            'objeto' => trim($data['objeto']),
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'monto_total' => isset($data['monto_total']) ? bcadd((string) $data['monto_total'], '0', 4) : null,
            'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function supplierSnapshot(ProveedorEdificioEloquentModel $association): array
    {
        $identity = $association->proveedor->tercero;

        return [
            'proveedorId' => $association->proveedor_id,
            'terceroId' => $identity->id,
            'tipoPersona' => $identity->tipo_persona->value,
            'nombre' => $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
                ? $identity->razon_social
                : trim($identity->nombres.' '.$identity->apellidos),
            'tipoIdentificacion' => $identity->tipo_identificacion->value,
            'identificacion' => $identity->identificacion,
            'nombreComercial' => $association->nombre_comercial,
            'correo' => $association->correo ?? $identity->correo,
            'telefono' => $association->telefono ?? $identity->telefono ?? $identity->celular,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(ContratoProveedorEloquentModel $contract): array
    {
        $identity = $contract->proveedor->tercero;

        return [
            'id' => $contract->id,
            'edificioId' => $contract->edificio_id,
            'edificio' => $contract->edificio->nombre,
            'proveedorId' => $contract->proveedor_id,
            'proveedor' => $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
                ? $identity->razon_social
                : trim($identity->nombres.' '.$identity->apellidos),
            'referencia' => $contract->referencia,
            'objeto' => $contract->objeto,
            'fechaInicio' => $contract->fecha_inicio->format('Y-m-d'),
            'fechaFin' => $contract->fecha_fin?->format('Y-m-d'),
            'montoTotal' => $contract->monto_total,
            'observaciones' => $contract->observaciones,
            'estado' => $contract->estado->value,
            'proveedorSnapshot' => $contract->proveedor_snapshot,
            'registradoAt' => $contract->registrado_at?->toIso8601String(),
            'anuladoAt' => $contract->anulado_at?->toIso8601String(),
            'motivoAnulacion' => $contract->motivo_anulacion,
            'createdAt' => $contract->created_at?->toIso8601String(),
            'updatedAt' => $contract->updated_at?->toIso8601String(),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
