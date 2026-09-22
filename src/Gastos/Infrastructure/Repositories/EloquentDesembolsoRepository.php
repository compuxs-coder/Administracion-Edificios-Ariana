<?php

namespace Src\Gastos\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoCuentaPorPagar;
use Src\Gastos\Domain\Enums\EstadoDesembolso;
use Src\Gastos\Domain\Enums\EstadoGasto;
use Src\Gastos\Domain\Enums\EstadoPagoGasto;
use Src\Gastos\Domain\Enums\FormaDesembolso;
use Src\Gastos\Infrastructure\Models\AplicacionDesembolsoEloquentModel;
use Src\Gastos\Infrastructure\Models\CuentaPorPagarEloquentModel;
use Src\Gastos\Infrastructure\Models\DesembolsoEloquentModel;
use Src\Gastos\Infrastructure\Models\GastoEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoPersona;

final class EloquentDesembolsoRepository implements DesembolsoRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = DesembolsoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::DESEMBOLSOS_VER))
            ->whereIn('estado', [EstadoDesembolso::REGISTRADO->value, EstadoDesembolso::ANULADO->value])
            ->with(['edificio', 'registradoPor'])
            ->withCount('aplicaciones')
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['proveedor_id'] ?? null, static fn (Builder $query, string $id) => $query->where('proveedor_id', $id))
            ->when($filters['forma_pago'] ?? null, static fn (Builder $query, string $form) => $query->where('forma_pago', $form))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['fecha_desde'] ?? null, static fn (Builder $query, string $date) => $query->whereDate('fecha_desembolso', '>=', $date))
            ->when($filters['fecha_hasta'] ?? null, static fn (Builder $query, string $date) => $query->whereDate('fecha_desembolso', '<=', $date))
            ->orderByDesc('fecha_desembolso')
            ->orderByDesc('numero');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (DesembolsoEloquentModel $payment): array => $this->serialize($payment))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $desembolsoId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::DESEMBOLSOS_VER);
        $payment = DesembolsoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->whereIn('estado', [EstadoDesembolso::REGISTRADO->value, EstadoDesembolso::ANULADO->value])
            ->with(['edificio', 'registradoPor', 'aplicaciones.cuentaPorPagar.gasto'])
            ->findOrFail($desembolsoId);

        return $this->serialize($payment, true);
    }

    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::DESEMBOLSOS_VER): array
    {
        $buildings = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
        $buildingIds = $buildings->pluck('id')->all();
        $allowedPairs = null;
        if ($permission === PermisoEdificio::DESEMBOLSOS_REGISTRAR) {
            $allowedPairs = CuentaPorPagarEloquentModel::query()
                ->whereIn('edificio_id', $buildingIds)
                ->where('estado', EstadoCuentaPorPagar::PENDIENTE->value)
                ->where('saldo', '>', 0)
                ->get(['edificio_id', 'proveedor_id'])
                ->mapWithKeys(static fn (CuentaPorPagarEloquentModel $account): array => [
                    $account->edificio_id.'|'.$account->proveedor_id => true,
                ]);
        }
        $suppliers = ProveedorEdificioEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->with('proveedor.tercero')
            ->get()
            ->filter(static fn (ProveedorEdificioEloquentModel $association): bool => $allowedPairs === null
                || $allowedPairs->has($association->edificio_id.'|'.$association->proveedor_id))
            ->map(function (ProveedorEdificioEloquentModel $association): array {
                $identity = $association->proveedor->tercero;

                return [
                    'id' => $association->proveedor_id,
                    'edificioId' => $association->edificio_id,
                    'nombre' => $this->supplierName($identity),
                    'identificacion' => $identity->identificacion,
                ];
            })
            ->sortBy([['nombre', 'asc'], ['identificacion', 'asc']])
            ->values()
            ->all();

        return [
            'edificios' => $buildings->map(static fn (EdificioEloquentModel $building): array => [
                'id' => $building->id,
                'nombre' => $building->nombre,
            ])->all(),
            'proveedores' => $suppliers,
        ];
    }

    public function preview(string $userId, string $edificioId, string $proveedorId, string $amount): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::DESEMBOLSOS_REGISTRAR);
        $supplier = $this->supplier($edificioId, $proveedorId);
        $accounts = $this->pendingAccounts($edificioId, $proveedorId);

        return $this->previewData($supplier, $accounts, $this->money($amount));
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        $date = $this->date($data['fecha_desembolso'] ?? null);
        $amount = $this->money($data['monto'] ?? null);
        $form = FormaDesembolso::tryFrom((string) ($data['forma_pago'] ?? ''));
        if ($form === null) {
            throw ValidationException::withMessages(['forma_pago' => 'La forma de desembolso no es válida.']);
        }

        return DB::transaction(function () use ($userId, $edificioId, $data, $date, $amount, $form): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::DESEMBOLSOS_REGISTRAR, true);
            $supplier = $this->supplier($edificioId, (string) ($data['proveedor_id'] ?? ''), true);
            $accounts = $this->pendingAccounts($edificioId, $supplier->proveedor_id, true);
            $preview = $this->previewData($supplier, $accounts, $amount);
            if (! hash_equals($preview['aplicacionFingerprint'], (string) ($data['aplicacion_fingerprint'] ?? ''))) {
                throw ValidationException::withMessages([
                    'aplicacion_fingerprint' => 'Las cuentas o sus saldos cambiaron. Actualice la previsualización antes de registrar.',
                ]);
            }
            $identity = $supplier->proveedor->tercero;

            $payment = new DesembolsoEloquentModel();
            $payment->fill([
                'edificio_id' => $edificioId,
                'proveedor_id' => $supplier->proveedor_id,
                'numero' => $this->nextNumber($date),
                'fecha_desembolso' => $date,
                'monto' => $amount,
                'forma_pago' => $form,
                'referencia' => $this->nullableTrim($data['referencia'] ?? null),
                'observacion' => $this->nullableTrim($data['observacion'] ?? null),
                'proveedor_nombre_snapshot' => $this->supplierName($identity),
                'proveedor_identificacion_snapshot' => $identity->identificacion,
                'estado' => EstadoDesembolso::PREPARANDO,
                'registrado_por' => $userId,
            ])->save();

            $accountsById = $accounts->keyBy('id');
            $expenseIds = collect($preview['cuentas'])->pluck('gastoId')->all();
            $expenses = GastoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->whereIn('id', $expenseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $now = CarbonImmutable::now();
            foreach ($preview['cuentas'] as $item) {
                /** @var CuentaPorPagarEloquentModel $account */
                $account = $accountsById->get($item['cuentaId']);
                /** @var GastoEloquentModel|null $expense */
                $expense = $expenses->get($item['gastoId']);
                if ($expense === null || $expense->estado !== EstadoGasto::REGISTRADO) {
                    throw ValidationException::withMessages(['cuentas' => 'Una cuenta seleccionada ya no corresponde a un gasto registrado.']);
                }

                $application = new AplicacionDesembolsoEloquentModel();
                $application->fill([
                    'edificio_id' => $edificioId,
                    'proveedor_id' => $supplier->proveedor_id,
                    'desembolso_id' => $payment->id,
                    'cuenta_por_pagar_id' => $account->id,
                    'monto_aplicado' => $item['montoAplicado'],
                ])->save();
                $account->fill(['saldo' => $item['saldoPosterior']])->save();
                $paid = bccomp($item['saldoPosterior'], '0.0000', 4) === 0;
                $expense->fill([
                    'estado_pago' => $paid ? EstadoPagoGasto::PAGADO : EstadoPagoGasto::PENDIENTE,
                    'pagado_at' => $paid ? $now : null,
                ])->save();
            }
            $payment->fill(['estado' => EstadoDesembolso::REGISTRADO])->save();

            return ['id' => $payment->id];
        });
    }

    public function cancel(string $userId, string $edificioId, string $desembolsoId, string $reason): void
    {
        DB::transaction(function () use ($userId, $edificioId, $desembolsoId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::DESEMBOLSOS_ANULAR, true);
            $payment = DesembolsoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($desembolsoId);
            if ($payment->estado !== EstadoDesembolso::REGISTRADO) {
                throw ValidationException::withMessages(['estado' => 'El desembolso ya fue anulado.']);
            }
            $applications = AplicacionDesembolsoEloquentModel::query()
                ->where('desembolso_id', $payment->id)
                ->orderBy('cuenta_por_pagar_id')
                ->get();
            $accounts = CuentaPorPagarEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('proveedor_id', $payment->proveedor_id)
                ->whereIn('id', $applications->pluck('cuenta_por_pagar_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $expenses = GastoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->whereIn('id', $accounts->pluck('gasto_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($applications as $application) {
                /** @var CuentaPorPagarEloquentModel|null $account */
                $account = $accounts->get($application->cuenta_por_pagar_id);
                if ($account === null || $account->estado !== EstadoCuentaPorPagar::PENDIENTE) {
                    throw ValidationException::withMessages(['desembolso' => 'Una cuenta aplicada ya no está disponible para restaurar su saldo.']);
                }
                $restored = bcadd($account->saldo, $application->monto_aplicado, 4);
                if (bccomp($restored, $account->monto_original, 4) > 0) {
                    throw ValidationException::withMessages(['desembolso' => 'La reversión excede el monto original de una cuenta.']);
                }
                /** @var GastoEloquentModel|null $expense */
                $expense = $expenses->get($account->gasto_id);
                if ($expense === null || $expense->estado !== EstadoGasto::REGISTRADO) {
                    throw ValidationException::withMessages(['desembolso' => 'El gasto asociado no permite revertir el desembolso.']);
                }
                $account->fill(['saldo' => $restored])->save();
                $expense->fill(['estado_pago' => EstadoPagoGasto::PENDIENTE, 'pagado_at' => null])->save();
            }
            $payment->fill([
                'estado' => EstadoDesembolso::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => CarbonImmutable::now(),
                'motivo_anulacion' => trim($reason),
            ])->save();
        });
    }

    /** @return Collection<int, CuentaPorPagarEloquentModel> */
    private function pendingAccounts(string $buildingId, string $supplierId, bool $lock = false): Collection
    {
        $query = CuentaPorPagarEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $supplierId)
            ->where('estado', EstadoCuentaPorPagar::PENDIENTE->value)
            ->where('saldo', '>', 0)
            ->with('gasto')
            ->orderBy('fecha_vencimiento')
            ->orderBy('created_at')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @param Collection<int, CuentaPorPagarEloquentModel> $accounts @return array<string, mixed> */
    private function previewData(ProveedorEdificioEloquentModel $supplier, Collection $accounts, string $amount): array
    {
        $balance = $accounts->reduce(
            static fn (string $total, CuentaPorPagarEloquentModel $account): string => bcadd($total, $account->saldo, 4),
            '0.0000',
        );
        if (bccomp($balance, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor no tiene cuentas por pagar con saldo pendiente.']);
        }
        if (bccomp($amount, $balance, 4) > 0) {
            throw ValidationException::withMessages(['monto' => 'El monto no puede exceder el saldo pendiente del proveedor.']);
        }

        $remaining = $amount;
        $items = [];
        foreach ($accounts as $account) {
            if (bccomp($remaining, '0.0000', 4) <= 0) {
                break;
            }
            $applied = bccomp($remaining, $account->saldo, 4) >= 0 ? $account->saldo : $remaining;
            $remaining = bcsub($remaining, $applied, 4);
            $items[] = [
                'cuentaId' => $account->id,
                'gastoId' => $account->gasto_id,
                'numeroGasto' => $account->gasto?->numero,
                'concepto' => $account->gasto?->concepto,
                'fechaVencimiento' => $account->fecha_vencimiento->format('Y-m-d'),
                'saldoAnterior' => $account->saldo,
                'montoAplicado' => $applied,
                'saldoPosterior' => bcsub($account->saldo, $applied, 4),
            ];
        }
        $identity = $supplier->proveedor->tercero;

        return [
            'proveedor' => [
                'id' => $supplier->proveedor_id,
                'nombre' => $this->supplierName($identity),
                'identificacion' => $identity->identificacion,
            ],
            'saldoPendiente' => $balance,
            'monto' => $amount,
            'montoAplicado' => $amount,
            'saldoPosterior' => bcsub($balance, $amount, 4),
            'cuentas' => $items,
            'aplicacionFingerprint' => hash('sha256', json_encode([
                'edificioId' => $supplier->edificio_id,
                'proveedorId' => $supplier->proveedor_id,
                'monto' => $amount,
                'cuentasAbiertas' => $accounts->map(static fn (CuentaPorPagarEloquentModel $account): array => [
                    'cuentaId' => $account->id,
                    'saldo' => $account->saldo,
                ])->all(),
                'aplicaciones' => array_map(static fn (array $item): array => [
                    'cuentaId' => $item['cuentaId'],
                    'montoAplicado' => $item['montoAplicado'],
                ], $items),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function authorizedBuilding(string $userId, string $buildingId, PermisoEdificio $permission, bool $lock = false): EdificioEloquentModel
    {
        if ($lock) {
            $building = EdificioEloquentModel::query()->lockForUpdate()->findOrFail($buildingId);
            abort_unless($this->access->hasPermission($userId, $buildingId, $permission), 404);

            return $building;
        }

        return EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->findOrFail($buildingId);
    }

    private function supplier(string $buildingId, string $supplierId, bool $lock = false): ProveedorEdificioEloquentModel
    {
        $query = ProveedorEdificioEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $supplierId)
            ->with('proveedor.tercero');
        if ($lock) {
            $query->lockForUpdate();
        }
        $supplier = $query->first();
        if ($supplier === null) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor no pertenece al edificio.']);
        }

        return $supplier;
    }

    private function nextNumber(CarbonImmutable $date): string
    {
        $year = (int) $date->format('Y');
        $table = $this->table('consecutivos_desembolso');
        $now = CarbonImmutable::now();
        DB::table($table)->insertOrIgnore(['anio' => $year, 'ultimo_numero' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $counter = DB::table($table)->where('anio', $year)->lockForUpdate()->first();
        $number = ((int) $counter->ultimo_numero) + 1;
        if ($number > 999999) {
            throw ValidationException::withMessages(['numero' => 'El consecutivo anual de desembolsos está agotado.']);
        }
        DB::table($table)->where('anio', $year)->update(['ultimo_numero' => $number, 'updated_at' => $now]);

        return sprintf('DES-%04d-%06d', $year, $number);
    }

    /** @return array<string, mixed> */
    private function serialize(DesembolsoEloquentModel $payment, bool $detail = false): array
    {
        $data = [
            'id' => $payment->id,
            'edificioId' => $payment->edificio_id,
            'edificio' => $payment->edificio?->nombre,
            'proveedorId' => $payment->proveedor_id,
            'proveedor' => $payment->proveedor_nombre_snapshot,
            'proveedorIdentificacion' => $payment->proveedor_identificacion_snapshot,
            'numero' => $payment->numero,
            'fechaDesembolso' => $payment->fecha_desembolso->format('Y-m-d'),
            'monto' => $payment->monto,
            'formaPago' => $payment->forma_pago->value,
            'referencia' => $payment->referencia,
            'observacion' => $payment->observacion,
            'estado' => $payment->estado->value,
            'registradoPor' => $payment->registradoPor?->name,
            'anuladoAt' => $payment->anulado_at?->toIso8601String(),
            'motivoAnulacion' => $payment->motivo_anulacion,
            'cantidadCuentas' => $payment->aplicaciones_count ?? $payment->aplicaciones->count(),
            'createdAt' => $payment->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $data['aplicaciones'] = $payment->aplicaciones
                ->sortBy(static function (AplicacionDesembolsoEloquentModel $application): string {
                    $account = $application->cuentaPorPagar;

                    return ($account?->fecha_vencimiento?->format('Y-m-d') ?? '').'|'
                        .($account?->created_at?->toIso8601String() ?? '').'|'
                        .$application->cuenta_por_pagar_id;
                })
                ->values()
                ->map(static function (AplicacionDesembolsoEloquentModel $application): array {
                    $account = $application->cuentaPorPagar;

                    return [
                        'id' => $application->id,
                        'cuentaId' => $application->cuenta_por_pagar_id,
                        'gastoId' => $account?->gasto_id,
                        'numeroGasto' => $account?->gasto?->numero,
                        'concepto' => $account?->gasto?->concepto,
                        'fechaVencimiento' => $account?->fecha_vencimiento?->format('Y-m-d'),
                        'montoAplicado' => $application->monto_aplicado,
                        'createdAt' => $application->created_at?->toIso8601String(),
                    ];
                })->all();
        }

        return $data;
    }

    private function supplierName(object $identity): string
    {
        return $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
            ? $identity->razon_social
            : trim($identity->nombres.' '.$identity->apellidos);
    }

    private function date(mixed $value): CarbonImmutable
    {
        $value = (string) $value;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['fecha_desembolso' => 'La fecha debe tener formato YYYY-MM-DD.']);
        }

        return $date;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages(['monto' => 'El monto debe ser decimal positivo con hasta cuatro decimales.']);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 10) {
            throw ValidationException::withMessages(['monto' => 'El monto supera la precisión permitida.']);
        }

        return $integer.'.'.str_pad($fraction, 4, '0');
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
}
