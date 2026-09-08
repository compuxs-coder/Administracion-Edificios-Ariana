<?php

namespace Src\Finanzas\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;
use Src\Finanzas\Domain\Enums\EstadoCargo;
use Src\Finanzas\Domain\Enums\EstadoPago;
use Src\Finanzas\Domain\Enums\EstadoReciboPago;
use Src\Finanzas\Domain\Enums\FormaPago;
use Src\Finanzas\Domain\Enums\OrigenPago;
use Src\Finanzas\Infrastructure\Models\AplicacionPagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ReciboPagoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class EloquentPagoRepository implements PagoRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function list(string $userId, array $filters): array
    {
        $query = PagoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER))
            ->with(['departamento', 'propietario', 'registradoPor', 'aplicaciones', 'recibo'])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('numero');
        foreach (['edificio_id', 'departamento_id', 'forma_pago', 'estado'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['propietario_id'] ?? null) !== null) {
            $query->whereHas('titulares', static fn (Builder $query) => $query->where('propietario_id', $filters['propietario_id']));
        }
        if (($filters['fecha_desde'] ?? null) !== null) {
            $query->whereDate('fecha_pago', '>=', $this->date($filters['fecha_desde'], 'fecha_desde')->format('Y-m-d'));
        }
        if (($filters['fecha_hasta'] ?? null) !== null) {
            $query->whereDate('fecha_pago', '<=', $this->date($filters['fecha_hasta'], 'fecha_hasta')->format('Y-m-d'));
        }

        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (PagoEloquentModel $pago): array => $this->serializePago($pago))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $pagoId): array
    {
        $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::FINANZAS_VER);
        $pago = PagoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['departamento', 'propietario', 'registradoPor', 'aplicaciones.cargo.concepto', 'recibo'])
            ->findOrFail($pagoId);

        return $this->serializePago($pago, true);
    }

    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        $edificios = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
        $edificioIds = $edificios->pluck('id')->all();

        return [
            'edificios' => $edificios->map(static fn (EdificioEloquentModel $edificio): array => ['id' => $edificio->id, 'nombre' => $edificio->nombre])->all(),
            'departamentos' => DepartamentoEloquentModel::query()
                ->whereIn('edificio_id', $edificioIds)
                ->orderBy('codigo')
                ->get(['id', 'edificio_id', 'codigo', 'nombre', 'estado'])
                ->map(static fn (DepartamentoEloquentModel $departamento): array => [
                    'id' => $departamento->id,
                    'edificioId' => $departamento->edificio_id,
                    'codigo' => $departamento->codigo,
                    'nombre' => $departamento->nombre,
                    'estado' => $departamento->estado->value,
                ])->all(),
            'propietarios' => PropietarioEloquentModel::query()
                ->whereHas('edificios', static fn (Builder $query) => $query->whereIn('edificios.id', $edificioIds))
                ->orderBy('nombres')
                ->get(['id', 'nombres', 'apellidos', 'razon_social', 'identificacion'])
                ->map(static fn (PropietarioEloquentModel $propietario): array => [
                    'id' => $propietario->id,
                    'nombre' => $propietario->razon_social ?? trim($propietario->nombres.' '.$propietario->apellidos),
                    'identificacion' => $propietario->identificacion,
                ])->all(),
        ];
    }

    public function preview(string $userId, string $edificioId, string $departamentoId, string $valor, string $fechaPago): array
    {
        $fecha = $this->date($fechaPago, 'fecha_pago');
        $monto = $this->money($valor, 'valor_recibido');
        $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::PAGOS_REGISTRAR);
        $departamento = $this->departamento($edificioId, $departamentoId);
        $cargos = $this->pendingCargos($edificioId, $departamento->id);
        $plan = $this->applicationPlan($cargos, $monto);
        $saldoPendiente = $this->sumCargos($cargos);
        $saldoFavorActual = $this->creditAvailable($edificioId, $departamento->id);

        return [
            'departamento' => ['id' => $departamento->id, 'codigo' => $departamento->codigo, 'nombre' => $departamento->nombre],
            'propietarios' => $this->owners($departamento->id, $fecha)->all(),
            'saldoPendiente' => $saldoPendiente,
            'saldoFavorActual' => $saldoFavorActual,
            'saldoNetoActual' => $this->netBalance($saldoPendiente, $saldoFavorActual),
            'valorRecibido' => $monto,
            'valorAplicado' => $plan['aplicado'],
            'saldoFavorNuevo' => $plan['restante'],
            'saldoDespues' => bcsub($saldoPendiente, $plan['aplicado'], 4),
            'cargos' => $plan['items'],
        ];
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        $fechaPago = $this->date($data['fecha_pago'] ?? null, 'fecha_pago');
        $monto = $this->money($data['valor_recibido'] ?? null, 'valor_recibido');
        $formaPago = FormaPago::tryFrom((string) ($data['forma_pago'] ?? ''));
        if ($formaPago === null) {
            throw ValidationException::withMessages(['forma_pago' => 'La forma de pago no es válida.']);
        }

        return DB::transaction(function () use ($userId, $edificioId, $data, $fechaPago, $monto, $formaPago): array {
            $edificio = $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::PAGOS_REGISTRAR, true);
            $departamento = $this->departamento($edificioId, (string) ($data['departamento_id'] ?? ''), true);
            $owners = $this->owners($departamento->id, $fechaPago);
            $cargos = $this->pendingCargos($edificioId, $departamento->id, true);
            $saldoAnterior = $this->sumCargos($cargos);
            $plan = $this->applicationPlan($cargos, $monto);

            $pago = new PagoEloquentModel();
            $pago->edificio_id = $edificioId;
            $pago->departamento_id = $departamento->id;
            $pago->propietario_id = $owners->count() === 1 ? $owners->first()['id'] : null;
            $pago->fill([
                'numero' => $this->nextNumber($fechaPago),
                'fecha_pago' => $fechaPago,
                'monto_recibido' => $monto,
                'forma_pago' => $formaPago,
                'referencia' => $this->nullableTrim($data['referencia'] ?? null),
                'observacion' => $this->nullableTrim($data['observacion'] ?? null),
                'estado' => EstadoPago::REGISTRADO,
                'origen' => OrigenPago::MANUAL,
                'metadata' => [
                    'propietarios' => $owners->all(),
                    'saldoAnterior' => $saldoAnterior,
                    'saldoPosterior' => bcsub($saldoAnterior, $plan['aplicado'], 4),
                    'aplicacion' => 'automatica_antiguedad',
                ],
                'registrado_por' => $userId,
            ])->save();
            foreach ($owners as $owner) {
                $titular = new \Src\Finanzas\Infrastructure\Models\PagoTitularEloquentModel();
                $titular->pago_id = $pago->id;
                $titular->propietario_id = $owner['id'];
                $titular->fill([
                    'nombre_snapshot' => $owner['nombre'],
                    'identificacion_snapshot' => $owner['identificacion'],
                    'porcentaje' => $owner['porcentaje'],
                ])->save();
            }

            $this->persistPlan($pago, $cargos, $plan['items']);
            $this->issueReceipt($pago, $edificio, $departamento, $owners, $plan['items'], $userId);

            return ['id' => $pago->id];
        });
    }

    public function applyCredit(string $userId, string $edificioId, string $pagoId): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $pagoId): array {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::PAGOS_APLICAR_SALDO, true);
            $pago = PagoEloquentModel::query()->where('edificio_id', $edificioId)->lockForUpdate()->findOrFail($pagoId);
            if ($pago->estado !== EstadoPago::REGISTRADO) {
                throw ValidationException::withMessages(['estado' => 'No se puede aplicar el saldo a favor de un pago anulado.']);
            }
            $credito = $this->creditOfPayment($pago);
            if (bccomp($credito, '0.0000', 4) <= 0) {
                throw ValidationException::withMessages(['saldo_favor' => 'El pago no tiene saldo a favor disponible.']);
            }
            $cargos = $this->pendingCargos($edificioId, $pago->departamento_id, true);
            $plan = $this->applicationPlan($cargos, $credito);
            if ($plan['items'] === []) {
                throw ValidationException::withMessages(['cargos' => 'No hay cargos pendientes para aplicar el saldo a favor.']);
            }
            $this->persistPlan($pago, $cargos, $plan['items']);

            return [
                'valorAplicado' => $plan['aplicado'],
                'saldoFavor' => bcsub($credito, $plan['aplicado'], 4),
            ];
        });
    }

    public function cancel(string $userId, string $edificioId, string $pagoId, string $motivo): void
    {
        DB::transaction(function () use ($userId, $edificioId, $pagoId, $motivo): void {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::PAGOS_ANULAR, true);
            $pago = PagoEloquentModel::query()->where('edificio_id', $edificioId)->lockForUpdate()->findOrFail($pagoId);
            if ($pago->estado !== EstadoPago::REGISTRADO) {
                throw ValidationException::withMessages(['estado' => 'El pago ya fue anulado.']);
            }
            $aplicaciones = AplicacionPagoEloquentModel::query()->where('pago_id', $pago->id)->orderBy('cargo_id')->get();
            $cargos = CargoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('departamento_id', $pago->departamento_id)
                ->whereIn('id', $aplicaciones->pluck('cargo_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($aplicaciones as $aplicacion) {
                /** @var CargoEloquentModel $cargo */
                $cargo = $cargos->get($aplicacion->cargo_id);
                if ($cargo === null || $cargo->estado === EstadoCargo::ANULADO) {
                    throw ValidationException::withMessages(['pago' => 'La aplicación no puede revertirse porque su cargo no es válido.']);
                }
                $nuevoSaldo = bcadd($cargo->saldo, $aplicacion->monto_aplicado, 4);
                if (bccomp($nuevoSaldo, $cargo->valor_original, 4) > 0) {
                    throw ValidationException::withMessages(['pago' => 'La reversión excede el valor original del cargo.']);
                }
                $cargo->fill(['saldo' => $nuevoSaldo, 'estado' => $this->cargoState($cargo->valor_original, $nuevoSaldo)])->save();
            }
            $recibo = ReciboPagoEloquentModel::query()->where('pago_id', $pago->id)->lockForUpdate()->first();
            if ($recibo === null || $recibo->estado !== EstadoReciboPago::EMITIDO) {
                throw ValidationException::withMessages(['recibo' => 'El pago no tiene un recibo emitido válido para anular.']);
            }
            $anuladoAt = CarbonImmutable::now();
            $motivo = trim($motivo);
            $pago->fill([
                'estado' => EstadoPago::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => $anuladoAt,
                'motivo_anulacion' => $motivo,
            ])->save();
            $recibo->fill([
                'estado' => EstadoReciboPago::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => $anuladoAt,
                'motivo_anulacion' => $motivo,
            ])->save();
        });
    }

    public function cartera(string $userId, array $filters): array
    {
        $departamentos = DepartamentoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER))
            ->when(($filters['edificio_id'] ?? null) !== null, static fn (Builder $query) => $query->where('edificio_id', $filters['edificio_id']))
            ->when(($filters['departamento_id'] ?? null) !== null, static fn (Builder $query) => $query->whereKey($filters['departamento_id']))
            ->with('edificio')
            ->orderBy('codigo')
            ->get();
        $items = $departamentos->map(function (DepartamentoEloquentModel $departamento): array {
            $cargos = CargoEloquentModel::query()
                ->where('edificio_id', $departamento->edificio_id)
                ->where('departamento_id', $departamento->id)
                ->where('estado', '!=', EstadoCargo::ANULADO->value)
                ->get();
            $cargosTotales = '0.0000';
            $pagosAplicados = '0.0000';
            $saldoPendiente = '0.0000';
            foreach ($cargos as $cargo) {
                $cargosTotales = bcadd($cargosTotales, $cargo->valor_original, 4);
                $saldoPendiente = bcadd($saldoPendiente, $cargo->saldo, 4);
                $pagosAplicados = bcadd($pagosAplicados, bcsub($cargo->valor_original, $cargo->saldo, 4), 4);
            }
            $saldoFavor = $this->creditAvailable($departamento->edificio_id, $departamento->id);

            return [
                'edificioId' => $departamento->edificio_id,
                'edificio' => $departamento->edificio?->nombre,
                'departamentoId' => $departamento->id,
                'departamento' => $departamento->codigo,
                'propietarios' => $this->owners($departamento->id, CarbonImmutable::today())->all(),
                'cargosTotales' => $cargosTotales,
                'pagosAplicados' => $pagosAplicados,
                'saldoPendiente' => $saldoPendiente,
                'saldoFavor' => $saldoFavor,
                'saldoNeto' => $this->netBalance($saldoPendiente, $saldoFavor),
            ];
        })->filter(static fn (array $item): bool => bccomp($item['cargosTotales'], '0.0000', 4) > 0 || bccomp($item['saldoFavor'], '0.0000', 4) > 0)->values();

        return ['items' => $items->all()];
    }

    private function authorizedEdificio(
        string $userId,
        string $edificioId,
        PermisoEdificio $permission,
        bool $lock = false,
    ): EdificioEloquentModel
    {
        $query = EdificioEloquentModel::query()->whereKey($this->access->buildingIds($userId, $permission));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    private function departamento(string $edificioId, string $departamentoId, bool $lock = false): DepartamentoEloquentModel
    {
        $query = DepartamentoEloquentModel::query()->where('edificio_id', $edificioId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $departamento = $query->find($departamentoId);
        if ($departamento === null) {
            throw ValidationException::withMessages(['departamento_id' => 'El departamento no pertenece al edificio.']);
        }

        return $departamento;
    }

    /** @return Collection<int, CargoEloquentModel> */
    private function pendingCargos(string $edificioId, string $departamentoId, bool $lock = false): Collection
    {
        $query = CargoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('departamento_id', $departamentoId)
            ->whereIn('estado', [EstadoCargo::PENDIENTE->value, EstadoCargo::PARCIAL->value])
            ->where('saldo', '>', 0)
            ->orderBy('fecha_vencimiento')
            ->orderBy('fecha_emision')
            ->orderBy('created_at')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @return Collection<int, array{id: string, nombre: string, identificacion: string, porcentaje: string}> */
    private function owners(string $departamentoId, CarbonImmutable $fecha): Collection
    {
        return DepartamentoPropietarioEloquentModel::query()
            ->where('departamento_id', $departamentoId)
            ->whereDate('fecha_inicio', '<=', $fecha->format('Y-m-d'))
            ->where(static fn (Builder $query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>', $fecha->format('Y-m-d')))
            ->orderBy('nombre_propietario')
            ->get()
            ->map(static fn (DepartamentoPropietarioEloquentModel $owner): array => [
                'id' => $owner->propietario_id,
                'nombre' => $owner->nombre_propietario,
                'identificacion' => $owner->identificacion_snapshot,
                'porcentaje' => $owner->porcentaje,
            ]);
    }

    /** @param Collection<int, CargoEloquentModel> $cargos @return array{items: list<array<string, string>>, aplicado: string, restante: string} */
    private function applicationPlan(Collection $cargos, string $monto): array
    {
        $restante = $monto;
        $aplicado = '0.0000';
        $items = [];
        foreach ($cargos as $cargo) {
            if (bccomp($restante, '0.0000', 4) <= 0) {
                break;
            }
            $valorAplicado = bccomp($restante, $cargo->saldo, 4) >= 0 ? $cargo->saldo : $restante;
            $restante = bcsub($restante, $valorAplicado, 4);
            $aplicado = bcadd($aplicado, $valorAplicado, 4);
            $items[] = [
                'cargoId' => $cargo->id,
                'concepto' => $cargo->concepto?->nombre ?? $cargo->descripcion,
                'periodo' => $cargo->periodo->format('Y-m'),
                'fechaVencimiento' => $cargo->fecha_vencimiento->format('Y-m-d'),
                'saldoAnterior' => $cargo->saldo,
                'valorAplicado' => $valorAplicado,
                'saldoPosterior' => bcsub($cargo->saldo, $valorAplicado, 4),
            ];
        }

        return ['items' => $items, 'aplicado' => $aplicado, 'restante' => $restante];
    }

    /** @param Collection<int, CargoEloquentModel> $cargos @param list<array<string, string>> $items */
    private function persistPlan(PagoEloquentModel $pago, Collection $cargos, array $items): void
    {
        $byId = $cargos->keyBy('id');
        foreach ($items as $item) {
            /** @var CargoEloquentModel $cargo */
            $cargo = $byId->get($item['cargoId']);
            $nuevoSaldo = $item['saldoPosterior'];
            $cargo->fill(['saldo' => $nuevoSaldo, 'estado' => $this->cargoState($cargo->valor_original, $nuevoSaldo)])->save();

            $aplicacion = new AplicacionPagoEloquentModel();
            $aplicacion->edificio_id = $pago->edificio_id;
            $aplicacion->departamento_id = $pago->departamento_id;
            $aplicacion->pago_id = $pago->id;
            $aplicacion->cargo_id = $cargo->id;
            $aplicacion->monto_aplicado = $item['valorAplicado'];
            $aplicacion->save();
        }
    }

    private function cargoState(string $valorOriginal, string $saldo): EstadoCargo
    {
        if (bccomp($saldo, '0.0000', 4) === 0) {
            return EstadoCargo::PAGADO;
        }

        return bccomp($saldo, $valorOriginal, 4) === 0 ? EstadoCargo::PENDIENTE : EstadoCargo::PARCIAL;
    }

    /** @param Collection<int, CargoEloquentModel> $cargos */
    private function sumCargos(Collection $cargos): string
    {
        return $cargos->reduce(static fn (string $total, CargoEloquentModel $cargo): string => bcadd($total, $cargo->saldo, 4), '0.0000');
    }

    private function creditAvailable(string $edificioId, string $departamentoId): string
    {
        return PagoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('departamento_id', $departamentoId)
            ->where('estado', EstadoPago::REGISTRADO->value)
            ->with('aplicaciones')
            ->get()
            ->reduce(function (string $total, PagoEloquentModel $pago): string {
                return bcadd($total, $this->creditOfPayment($pago), 4);
            }, '0.0000');
    }

    private function creditOfPayment(PagoEloquentModel $pago): string
    {
        $aplicado = $pago->relationLoaded('aplicaciones')
            ? $pago->aplicaciones->reduce(static fn (string $total, AplicacionPagoEloquentModel $aplicacion): string => bcadd($total, $aplicacion->monto_aplicado, 4), '0.0000')
            : (string) AplicacionPagoEloquentModel::query()->where('pago_id', $pago->id)->sum('monto_aplicado');

        return bcsub($pago->monto_recibido, $aplicado, 4);
    }

    private function nextNumber(CarbonImmutable $fechaPago): string
    {
        $table = $this->table('consecutivos_pago');
        $now = CarbonImmutable::now();
        DB::table($table)->insertOrIgnore(['clave' => 'pagos', 'ultimo_numero' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $consecutivo = DB::table($table)->where('clave', 'pagos')->lockForUpdate()->first();
        $numero = ((int) $consecutivo->ultimo_numero) + 1;
        DB::table($table)->where('clave', 'pagos')->update(['ultimo_numero' => $numero, 'updated_at' => $now]);

        return sprintf('PAG-%s-%06d', $fechaPago->format('Y'), $numero);
    }

    /** @param Collection<int, array{id: string, nombre: string, identificacion: string, porcentaje: string}> $owners @param list<array<string, string>> $aplicaciones */
    private function issueReceipt(PagoEloquentModel $pago, EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento, Collection $owners, array $aplicaciones, string $userId): void
    {
        $recibo = new ReciboPagoEloquentModel();
        $recibo->edificio_id = $pago->edificio_id;
        $recibo->departamento_id = $pago->departamento_id;
        $recibo->pago_id = $pago->id;
        $recibo->fill([
            'numero' => $this->nextReceiptNumber($pago->fecha_pago),
            'fecha_pago_snapshot' => $pago->fecha_pago,
            'edificio_nombre_snapshot' => $edificio->nombre,
            'departamento_codigo_snapshot' => $departamento->codigo,
            'departamento_nombre_snapshot' => $departamento->nombre,
            'titulares_snapshot' => $owners->values()->all(),
            'monto_recibido_snapshot' => $pago->monto_recibido,
            'forma_pago_snapshot' => $pago->forma_pago->value,
            'referencia_snapshot' => $pago->referencia,
            'aplicaciones_snapshot' => $aplicaciones,
            'estado' => EstadoReciboPago::EMITIDO,
            'emitido_por' => $userId,
        ])->save();
    }

    private function nextReceiptNumber(CarbonImmutable $fechaPago): string
    {
        $table = $this->table('consecutivos_recibo');
        $now = CarbonImmutable::now();
        $year = (int) $fechaPago->format('Y');
        DB::table($table)->insertOrIgnore(['anio' => $year, 'ultimo_numero' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $consecutivo = DB::table($table)->where('anio', $year)->lockForUpdate()->first();
        $numero = ((int) $consecutivo->ultimo_numero) + 1;
        if ($numero > 999999) {
            throw ValidationException::withMessages(['recibo' => 'El consecutivo anual de recibos está agotado.']);
        }
        DB::table($table)->where('anio', $year)->update(['ultimo_numero' => $numero, 'updated_at' => $now]);

        return sprintf('REC-%s-%06d', $fechaPago->format('Y'), $numero);
    }

    /** @return array<string, mixed> */
    private function serializePago(PagoEloquentModel $pago, bool $detail = false): array
    {
        $aplicaciones = $pago->relationLoaded('aplicaciones') ? $pago->aplicaciones : collect();
        $aplicadoHistorico = $aplicaciones->reduce(static fn (string $total, AplicacionPagoEloquentModel $aplicacion): string => bcadd($total, $aplicacion->monto_aplicado, 4), '0.0000');
        $aplicado = $pago->estado === EstadoPago::REGISTRADO ? $aplicadoHistorico : '0.0000';
        $saldoFavor = $pago->estado === EstadoPago::REGISTRADO ? bcsub($pago->monto_recibido, $aplicadoHistorico, 4) : '0.0000';
        $owners = $pago->metadata['propietarios'] ?? [];

        return [
            'id' => $pago->id,
            'edificioId' => $pago->edificio_id,
            'departamentoId' => $pago->departamento_id,
            'departamento' => $pago->departamento?->codigo,
            'propietarioId' => $pago->propietario_id,
            'propietario' => collect($owners)->pluck('nombre')->implode(', ') ?: ($pago->propietario === null ? '' : $this->ownerName($pago->propietario)),
            'numero' => $pago->numero,
            'fechaPago' => $pago->fecha_pago->format('Y-m-d'),
            'valorRecibido' => $pago->monto_recibido,
            'valorAplicado' => $aplicado,
            'valorAplicadoHistorico' => $aplicadoHistorico,
            'saldoFavor' => $saldoFavor,
            'formaPago' => $pago->forma_pago->value,
            'referencia' => $pago->referencia,
            'observacion' => $pago->observacion,
            'estado' => $pago->estado->value,
            'origen' => $pago->origen->value,
            'registradoPor' => $pago->registradoPor?->name,
            'anuladoAt' => $pago->anulado_at?->toISOString(),
            'motivoAnulacion' => $pago->motivo_anulacion,
            'saldoAnterior' => $pago->metadata['saldoAnterior'] ?? null,
            'saldoPosterior' => $pago->metadata['saldoPosterior'] ?? null,
            'recibo' => $pago->recibo === null ? null : ['id' => $pago->recibo->id, 'numero' => $pago->recibo->numero, 'estado' => $pago->recibo->estado->value],
            'propietarios' => $detail ? $owners : null,
            'aplicaciones' => $detail ? $aplicaciones->map(static fn (AplicacionPagoEloquentModel $aplicacion): array => [
                'id' => $aplicacion->id,
                'cargoId' => $aplicacion->cargo_id,
                'concepto' => $aplicacion->cargo?->concepto?->nombre ?? $aplicacion->cargo?->descripcion,
                'periodo' => $aplicacion->cargo?->periodo?->format('Y-m'),
                'valorAplicado' => $aplicacion->monto_aplicado,
                'createdAt' => $aplicacion->created_at?->toISOString(),
            ])->all() : null,
        ];
    }

    private function netBalance(string $saldoPendiente, string $saldoFavor): string
    {
        $neto = bcsub($saldoPendiente, $saldoFavor, 4);

        return bccomp($neto, '0.0000', 4) < 0 ? '0.0000' : $neto;
    }

    private function ownerName(PropietarioEloquentModel $propietario): string
    {
        return $propietario->razon_social ?? trim($propietario->nombres.' '.$propietario->apellidos);
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        $value = (string) $value;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'La fecha debe tener formato YYYY-MM-DD.']);
        }

        return $date;
    }

    private function money(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => 'El valor debe ser decimal positivo con hasta cuatro decimales.']);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 10) {
            throw ValidationException::withMessages([$field => 'El valor supera la precisión permitida.']);
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
        return DB::connection()->getDriverName() === 'pgsql' ? config('database.application_schema').'.'.$name : $name;
    }
}
