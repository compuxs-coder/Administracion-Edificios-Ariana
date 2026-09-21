<?php

namespace Src\Gastos\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoContratoProveedor;
use Src\Gastos\Domain\Enums\EstadoCuentaPorPagar;
use Src\Gastos\Domain\Enums\EstadoGasto;
use Src\Gastos\Domain\Enums\EstadoPagoGasto;
use Src\Gastos\Domain\Enums\EstadoProveedor;
use Src\Gastos\Domain\Enums\TipoPagoGasto;
use Src\Gastos\Infrastructure\Models\ContratoProveedorEloquentModel;
use Src\Gastos\Infrastructure\Models\CuentaPorPagarEloquentModel;
use Src\Gastos\Infrastructure\Models\GastoEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoPersona;

final class EloquentGastoRepository implements GastoRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = GastoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::GASTOS_VER))
            ->with(['edificio', 'proveedor.tercero', 'contrato', 'cuentaPorPagar'])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['proveedor_id'] ?? null, static fn (Builder $query, string $id) => $query->where('proveedor_id', $id))
            ->when($filters['contrato_id'] ?? null, static fn (Builder $query, string $id) => $query->where('contrato_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['tipo_pago'] ?? null, static fn (Builder $query, string $type) => $query->where('tipo_pago', $type))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $query) => $query
                    ->whereRaw('LOWER(COALESCE(numero, ?)) LIKE ?', ['', $term])
                    ->orWhereRaw('LOWER(concepto) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(referencia, ?)) LIKE ?', ['', $term]));
            })
            ->orderByDesc('fecha_gasto')
            ->orderByDesc('created_at');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (GastoEloquentModel $expense): array => $this->serialize($expense))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $gastoId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_VER);
        $expense = GastoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['edificio', 'proveedor.tercero', 'contrato', 'cuentaPorPagar'])
            ->findOrFail($gastoId);

        return $this->serialize($expense);
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $this->activeSupplier($edificioId, $data['proveedor_id'], true);
            $this->contract($edificioId, $data['proveedor_id'], $data['contrato_id'] ?? null, true);
            $expense = new GastoEloquentModel();
            $expense->fill([
                'edificio_id' => $edificioId,
                'proveedor_id' => $data['proveedor_id'],
                ...$this->persistenceData($data),
                'estado' => EstadoGasto::BORRADOR,
            ])->save();

            return ['id' => $expense->id];
        });
    }

    public function update(string $userId, string $edificioId, string $gastoId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $gastoId, $data): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $expense = $this->lockedExpense($edificioId, $gastoId);
            if ($expense->estado !== EstadoGasto::BORRADOR) {
                throw ValidationException::withMessages(['estado' => 'Sólo se pueden editar gastos en borrador.']);
            }
            $this->activeSupplier($edificioId, $data['proveedor_id'], true);
            $this->contract($edificioId, $data['proveedor_id'], $data['contrato_id'] ?? null, true);
            $expense->proveedor_id = $data['proveedor_id'];
            $expense->fill($this->persistenceData($data))->save();
        });
    }

    public function register(string $userId, string $edificioId, string $gastoId): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $gastoId): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_GESTIONAR, true);
            $expense = $this->lockedExpense($edificioId, $gastoId);
            if ($expense->estado !== EstadoGasto::BORRADOR) {
                throw ValidationException::withMessages(['estado' => 'Sólo se puede registrar un gasto en borrador.']);
            }
            if (bccomp((string) $expense->monto, '0', 4) <= 0) {
                throw ValidationException::withMessages(['monto' => 'El monto debe ser mayor que cero.']);
            }
            if ($expense->tipo_pago === TipoPagoGasto::CREDITO && $expense->fecha_vencimiento === null) {
                throw ValidationException::withMessages(['fecha_vencimiento' => 'El gasto a crédito requiere fecha de vencimiento.']);
            }

            $supplier = $this->activeSupplier($edificioId, $expense->proveedor_id, true);
            $contract = $this->contract($edificioId, $expense->proveedor_id, $expense->contrato_id, true);
            $now = CarbonImmutable::now();
            $expense->fill([
                'numero' => $this->nextNumber($expense->fecha_gasto),
                'estado' => EstadoGasto::REGISTRADO,
                'estado_pago' => $expense->tipo_pago === TipoPagoGasto::CONTADO
                    ? EstadoPagoGasto::PAGADO
                    : EstadoPagoGasto::PENDIENTE,
                'pagado_at' => $expense->tipo_pago === TipoPagoGasto::CONTADO ? $now : null,
                'proveedor_snapshot' => $this->supplierSnapshot($supplier),
                'contrato_snapshot' => $contract === null ? null : $this->contractSnapshot($contract),
                'registrado_por' => $userId,
                'registrado_at' => $now,
            ])->save();

            if ($expense->tipo_pago === TipoPagoGasto::CREDITO) {
                $amount = bcadd((string) $expense->monto, '0', 4);
                $account = new CuentaPorPagarEloquentModel();
                $account->fill([
                    'edificio_id' => $expense->edificio_id,
                    'gasto_id' => $expense->id,
                    'proveedor_id' => $expense->proveedor_id,
                    'fecha_vencimiento' => $expense->fecha_vencimiento,
                    'monto_original' => $amount,
                    'saldo' => $amount,
                    'estado' => EstadoCuentaPorPagar::PENDIENTE,
                ])->save();
            }

            return ['numero' => (string) $expense->numero];
        });
    }

    public function cancel(string $userId, string $edificioId, string $gastoId, string $reason): void
    {
        DB::transaction(function () use ($userId, $edificioId, $gastoId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::GASTOS_ANULAR, true);
            $expense = $this->lockedExpense($edificioId, $gastoId);
            if ($expense->estado !== EstadoGasto::REGISTRADO) {
                throw ValidationException::withMessages(['estado' => 'Sólo se puede anular un gasto registrado.']);
            }
            $now = CarbonImmutable::now();
            $account = CuentaPorPagarEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('gasto_id', $expense->id)
                ->lockForUpdate()
                ->first();
            if ($expense->tipo_pago === TipoPagoGasto::CREDITO && ($account === null || $account->estado !== EstadoCuentaPorPagar::PENDIENTE)) {
                throw ValidationException::withMessages(['estado' => 'La cuenta por pagar pendiente del gasto no está disponible.']);
            }
            if ($expense->tipo_pago === TipoPagoGasto::CONTADO && $account !== null) {
                throw ValidationException::withMessages(['estado' => 'Un gasto de contado no puede tener cuenta por pagar.']);
            }

            $expense->fill([
                'estado' => EstadoGasto::ANULADO,
                'estado_pago' => EstadoPagoGasto::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => $now,
                'motivo_anulacion' => trim($reason),
            ])->save();
            if ($account !== null) {
                $account->fill([
                    'estado' => EstadoCuentaPorPagar::ANULADA,
                    'anulado_at' => $now,
                ])->save();
            }
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

    private function contract(string $buildingId, string $supplierId, ?string $contractId, bool $lock): ?ContratoProveedorEloquentModel
    {
        if ($contractId === null) {
            return null;
        }
        $query = ContratoProveedorEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $supplierId)
            ->where('estado', EstadoContratoProveedor::REGISTRADO->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $contract = $query->find($contractId);
        if ($contract === null) {
            throw ValidationException::withMessages(['contrato_id' => 'El contrato no corresponde al proveedor o no está registrado.']);
        }

        return $contract;
    }

    private function lockedExpense(string $buildingId, string $expenseId): GastoEloquentModel
    {
        return GastoEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->lockForUpdate()
            ->findOrFail($expenseId);
    }

    private function nextNumber(CarbonImmutable $date): string
    {
        $year = (int) $date->format('Y');
        $table = DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.consecutivos_gasto'
            : 'consecutivos_gasto';
        $now = CarbonImmutable::now();
        DB::table($table)->insertOrIgnore([
            'anio' => $year,
            'ultimo_numero' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $counter = DB::table($table)->where('anio', $year)->lockForUpdate()->first();
        $number = ((int) $counter->ultimo_numero) + 1;
        if ($number > 999999) {
            throw ValidationException::withMessages(['numero' => 'El consecutivo anual de gastos está agotado.']);
        }
        DB::table($table)->where('anio', $year)->update(['ultimo_numero' => $number, 'updated_at' => $now]);

        return sprintf('GAS-%04d-%06d', $year, $number);
    }

    /** @return array<string, mixed> */
    private function persistenceData(array $data): array
    {
        return [
            'contrato_id' => $data['contrato_id'] ?? null,
            'fecha_gasto' => $data['fecha_gasto'],
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'concepto' => trim($data['concepto']),
            'referencia' => $this->nullableTrim($data['referencia'] ?? null),
            'monto' => bcadd((string) $data['monto'], '0', 4),
            'tipo_pago' => $data['tipo_pago'],
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
    private function contractSnapshot(ContratoProveedorEloquentModel $contract): array
    {
        return [
            'contratoId' => $contract->id,
            'referencia' => $contract->referencia,
            'objeto' => $contract->objeto,
            'fechaInicio' => $contract->fecha_inicio->format('Y-m-d'),
            'fechaFin' => $contract->fecha_fin?->format('Y-m-d'),
            'montoTotal' => $contract->monto_total,
            'proveedor' => $contract->proveedor_snapshot,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(GastoEloquentModel $expense): array
    {
        $identity = $expense->proveedor->tercero;

        return [
            'id' => $expense->id,
            'edificioId' => $expense->edificio_id,
            'edificio' => $expense->edificio->nombre,
            'proveedorId' => $expense->proveedor_id,
            'proveedor' => $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
                ? $identity->razon_social
                : trim($identity->nombres.' '.$identity->apellidos),
            'contratoId' => $expense->contrato_id,
            'numero' => $expense->numero,
            'fechaGasto' => $expense->fecha_gasto->format('Y-m-d'),
            'fechaVencimiento' => $expense->fecha_vencimiento?->format('Y-m-d'),
            'concepto' => $expense->concepto,
            'referencia' => $expense->referencia,
            'monto' => $expense->monto,
            'tipoPago' => $expense->tipo_pago->value,
            'estadoPago' => $expense->estado_pago?->value,
            'pagadoAt' => $expense->pagado_at?->toIso8601String(),
            'observaciones' => $expense->observaciones,
            'estado' => $expense->estado->value,
            'proveedorSnapshot' => $expense->proveedor_snapshot,
            'contratoSnapshot' => $expense->contrato_snapshot,
            'cuentaPorPagarId' => $expense->cuentaPorPagar?->id,
            'registradoAt' => $expense->registrado_at?->toIso8601String(),
            'anuladoAt' => $expense->anulado_at?->toIso8601String(),
            'motivoAnulacion' => $expense->motivo_anulacion,
            'createdAt' => $expense->created_at?->toIso8601String(),
            'updatedAt' => $expense->updated_at?->toIso8601String(),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
