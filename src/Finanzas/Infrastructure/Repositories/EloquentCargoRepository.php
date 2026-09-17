<?php

namespace Src\Finanzas\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;
use Src\Finanzas\Domain\Enums\AlcanceTarifa;
use Src\Finanzas\Domain\Enums\BaseCalculoInteres;
use Src\Finanzas\Domain\Enums\EstadoCargo;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\EstadoLoteGeneracion;
use Src\Finanzas\Domain\Enums\EstadoPago;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\OrigenCargo;
use Src\Finanzas\Domain\Enums\PeriodicidadCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\LecturaConsumoEloquentModel;
use Src\Finanzas\Infrastructure\Models\LoteGeneracionCargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;

final class EloquentCargoRepository implements CargoRepositoryInterface
{
    private const MAX_MONEY = '9999999999.9999';

    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function list(string $userId, array $filters): array
    {
        $query = CargoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER))
            ->with(['departamento', 'concepto', 'tarifa', 'lote'])
            ->orderByDesc('periodo')
            ->orderBy('departamento_id');
        foreach (['edificio_id', 'departamento_id', 'concepto_cobro_id', 'estado', 'origen'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['periodo'] ?? null) !== null) {
            $query->whereDate('periodo', $this->period($filters['periodo'])->format('Y-m-d'));
        }

        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (CargoEloquentModel $cargo): array => $this->serializeCargo($cargo))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $cargoId): array
    {
        $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::FINANZAS_VER);
        $cargo = CargoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['departamento', 'concepto', 'tarifa', 'lote', 'aplicacionesPago.pago'])
            ->findOrFail($cargoId);

        return $this->serializeCargo($cargo, true);
    }

    public function preview(?string $userId, string $edificioId, string $periodo, ?string $conceptoId = null): array
    {
        $periodoDate = $this->period($periodo);

        return $this->buildPreview($userId, $edificioId, $periodoDate, $conceptoId);
    }

    public function generate(?string $userId, string $edificioId, string $periodo, ?string $conceptoId = null): array
    {
        $periodoDate = $this->period($periodo);

        return DB::transaction(function () use ($userId, $edificioId, $periodoDate, $conceptoId): array {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CARGOS_GENERAR, true);
            $preview = $this->buildPreview($userId, $edificioId, $periodoDate, $conceptoId, true);
            if ($this->moneyOutOfRange($preview['totalValor'])) {
                throw ValidationException::withMessages([
                    'periodo' => 'El total del lote supera la precisión monetaria permitida.',
                ]);
            }
            $lote = new LoteGeneracionCargoEloquentModel();
            $lote->edificio_id = $edificioId;
            $lote->fill([
                'concepto_cobro_id' => $conceptoId,
                'periodo' => $periodoDate,
                'ejecutado_por' => $userId,
                'fecha_ejecucion' => CarbonImmutable::now(),
                'estado' => EstadoLoteGeneracion::PROCESANDO,
                'total_departamentos' => count(array_unique(array_column($preview['cargos'], 'departamentoId'))),
                'origen' => OrigenCargo::AUTOMATICO,
                'metadata' => ['periodo' => $periodoDate->format('Y-m'), 'modo' => 'generacion_manual_o_programada'],
            ])->save();

            $created = 0;
            $skipped = $preview['cantidadOmitidos'];
            $total = '0.0000';
            $errors = $preview['advertencias'];
            foreach ($preview['cargos'] as $item) {
                if ($this->automaticExists($edificioId, $item, $periodoDate)) {
                    $skipped++;
                    $errors[] = $this->warning($item, 'CARGO_DUPLICADO', 'Ya existe un cargo automático para este período.');
                    continue;
                }

                $cargo = new CargoEloquentModel();
                $cargo->edificio_id = $edificioId;
                $cargo->departamento_id = $item['departamentoId'];
                $cargo->concepto_cobro_id = $item['conceptoId'];
                $cargo->tarifa_id = $item['tarifaId'];
                $cargo->lectura_consumo_id = $item['lecturaConsumoId'];
                $cargo->propietario_id = $item['propietarioId'];
                $cargo->lote_generacion_id = $lote->id;
                $cargo->fill([
                    'periodo' => $periodoDate,
                    'fecha_emision' => $periodoDate,
                    'fecha_vencimiento' => $periodoDate->endOfMonth(),
                    'descripcion' => $item['descripcion'],
                    'valor_original' => $item['valor'],
                    'saldo' => $item['valor'],
                    'estado' => EstadoCargo::PENDIENTE,
                    'origen' => OrigenCargo::AUTOMATICO,
                    'referencia_generacion' => $this->automaticReference($edificioId, $item, $periodoDate),
                    'metadata' => $item['metadata'],
                    'created_by' => $userId,
                ]);
                try {
                    $cargo->save();
                } catch (UniqueConstraintViolationException) {
                    $skipped++;
                    $errors[] = $this->warning($item, 'CARGO_DUPLICADO', 'Otro proceso ya generó el cargo.');
                    continue;
                }
                $created++;
                $total = bcadd($total, $item['valor'], 4);
            }

            $lote->fill([
                'estado' => $errors === [] ? EstadoLoteGeneracion::COMPLETADO : EstadoLoteGeneracion::COMPLETADO_CON_ERRORES,
                'cargos_creados' => $created,
                'cargos_omitidos' => $skipped,
                'errores' => $errors,
                'total_valor' => $total,
            ])->save();

            return [
                'loteId' => $lote->id,
                'cargosCreados' => $created,
                'cargosOmitidos' => $skipped,
                'totalValor' => $total,
                'advertencias' => $errors,
            ];
        });
    }

    public function createManual(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CARGOS_CREAR, true);
            $departamento = DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoEstructura::ACTIVO->value)
                ->lockForUpdate()
                ->find($data['departamento_id'] ?? null);
            if ($departamento === null) {
                throw ValidationException::withMessages(['departamento_id' => 'El departamento no pertenece al edificio o está inactivo.']);
            }
            $concepto = ConceptoCobroEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoConceptoCobro::ACTIVO->value)
                ->lockForUpdate()
                ->find($data['concepto_cobro_id'] ?? null);
            if ($concepto === null) {
                throw ValidationException::withMessages(['concepto_cobro_id' => 'El concepto no pertenece al edificio o está inactivo.']);
            }
            $periodo = $this->period($data['periodo'] ?? null);
            $emision = $this->date($data['fecha_emision'] ?? null, 'fecha_emision');
            $vencimiento = $this->date($data['fecha_vencimiento'] ?? null, 'fecha_vencimiento');
            if ($vencimiento->lessThan($emision)) {
                throw ValidationException::withMessages(['fecha_vencimiento' => 'La fecha de vencimiento no puede ser anterior a la emisión.']);
            }
            $valor = $this->money($data['valor'] ?? null, 'valor', true);
            $owners = $this->owners($departamento->id, $emision);

            $cargo = new CargoEloquentModel();
            $cargo->edificio_id = $edificioId;
            $cargo->departamento_id = $departamento->id;
            $cargo->concepto_cobro_id = $concepto->id;
            $cargo->propietario_id = $owners->count() === 1 ? $owners->first()['id'] : null;
            $cargo->fill([
                'periodo' => $periodo,
                'fecha_emision' => $emision,
                'fecha_vencimiento' => $vencimiento,
                'descripcion' => trim((string) ($data['descripcion'] ?? '')) ?: $concepto->nombre,
                'valor_original' => $valor,
                'saldo' => $valor,
                'estado' => EstadoCargo::PENDIENTE,
                'origen' => OrigenCargo::MANUAL,
                'metadata' => [
                    'tipoCalculo' => 'manual',
                    'concepto' => ['codigo' => $concepto->codigo, 'nombre' => $concepto->nombre],
                    'propietarios' => $owners->all(),
                ],
                'created_by' => $userId,
            ])->save();

            return ['id' => $cargo->id];
        });
    }

    public function cancel(string $userId, string $edificioId, string $cargoId, string $motivo): void
    {
        DB::transaction(function () use ($userId, $edificioId, $cargoId, $motivo): void {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CARGOS_ANULAR, true);
            $cargo = CargoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($cargoId);
            if ($cargo->estado !== EstadoCargo::PENDIENTE || bccomp($cargo->saldo, $cargo->valor_original, 4) !== 0) {
                throw ValidationException::withMessages(['estado' => 'Sólo se pueden anular cargos pendientes sin pagos aplicados.']);
            }

            $cargo->fill([
                'estado' => EstadoCargo::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => CarbonImmutable::now(),
                'motivo_anulacion' => trim($motivo),
            ])->save();
        });
    }

    public function automatic(string $periodo, ?string $edificioId = null, ?string $conceptoId = null, bool $dryRun = false): array
    {
        $periodoDate = $this->period($periodo);
        $edificios = EdificioEloquentModel::query()
            ->where('estado', EstadoEstructura::ACTIVO->value)
            ->when($edificioId !== null, static fn (Builder $query) => $query->whereKey($edificioId))
            ->when($conceptoId !== null && $edificioId === null, static fn (Builder $query) => $query->whereIn(
                'id',
                ConceptoCobroEloquentModel::query()->whereKey($conceptoId)->select('edificio_id'),
            ))
            ->orderBy('id')
            ->get();

        return $edificios->map(function (EdificioEloquentModel $edificio) use ($periodoDate, $conceptoId, $dryRun): array {
            if ($dryRun) {
                return ['edificioId' => $edificio->id, 'dryRun' => true, ...$this->buildPreview(null, $edificio->id, $periodoDate, $conceptoId)];
            }

            return ['edificioId' => $edificio->id, 'dryRun' => false, ...$this->generate(null, $edificio->id, $periodoDate->format('Y-m'), $conceptoId)];
        })->all();
    }

    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        $edificios = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
        $ids = $edificios->pluck('id')->all();

        return [
            'edificios' => $edificios->map(static fn (EdificioEloquentModel $edificio): array => ['id' => $edificio->id, 'nombre' => $edificio->nombre])->all(),
            'conceptos' => ConceptoCobroEloquentModel::query()
                ->whereIn('edificio_id', $ids)
                ->orderBy('codigo')
                ->get()
                ->map(static fn (ConceptoCobroEloquentModel $concepto): array => [
                    'id' => $concepto->id,
                    'edificioId' => $concepto->edificio_id,
                    'codigo' => $concepto->codigo,
                    'nombre' => $concepto->nombre,
                    'estado' => $concepto->estado->value,
                ])->all(),
            'departamentos' => DepartamentoEloquentModel::query()
                ->whereIn('edificio_id', $ids)
                ->where('estado', EstadoEstructura::ACTIVO->value)
                ->orderBy('codigo')
                ->get()
                ->map(static fn (DepartamentoEloquentModel $departamento): array => [
                    'id' => $departamento->id,
                    'edificioId' => $departamento->edificio_id,
                    'codigo' => $departamento->codigo,
                    'nombre' => $departamento->nombre,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildPreview(?string $userId, string $edificioId, CarbonImmutable $periodo, ?string $conceptoId, bool $lock = false): array
    {
        $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CARGOS_GENERAR, $lock);
        $concepts = ConceptoCobroEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('estado', EstadoConceptoCobro::ACTIVO->value)
            ->when($conceptoId !== null, static fn (Builder $query) => $query->whereKey($conceptoId))
            ->orderBy('codigo')
            ->when($lock, static fn (Builder $query) => $query->lockForUpdate())
            ->get();
        if ($conceptoId !== null && $concepts->isEmpty()) {
            throw ValidationException::withMessages(['concepto_cobro_id' => 'El concepto no pertenece al edificio o está inactivo.']);
        }

        $cargos = [];
        $advertencias = [];
        $omitidos = 0;
        $total = '0.0000';
        foreach ($concepts as $concepto) {
            if ($concepto->periodicidad === PeriodicidadCobro::MANUAL) {
                continue;
            }
            $tarifa = $this->tarifaVigente($edificioId, $concepto->id, $periodo, $lock);
            if ($tarifa === null) {
                $omitidos += $this->activeDepartmentCount($edificioId, $lock);
                $advertencias[] = ['codigo' => 'SIN_TARIFA_VIGENTE', 'conceptoId' => $concepto->id, 'concepto' => $concepto->codigo, 'mensaje' => 'No existe una tarifa vigente para el período.'];
                continue;
            }
            if (! $this->isDue($concepto->periodicidad, $periodo, $tarifa->fecha_inicio)) {
                continue;
            }
            $departamentos = $this->departamentosAlcance($edificioId, $tarifa, $lock);
            if ($departamentos->isEmpty()) {
                $advertencias[] = ['codigo' => 'SIN_DEPARTAMENTOS_APLICABLES', 'conceptoId' => $concepto->id, 'concepto' => $concepto->codigo, 'mensaje' => 'No hay departamentos activos dentro del alcance.'];
                continue;
            }
            foreach ($departamentos as $departamento) {
                $calculo = $this->calculate($concepto, $tarifa, $departamento, $periodo, $lock);
                if ($calculo['warning'] !== null) {
                    $omitidos++;
                    $advertencias[] = $this->warning([
                        'departamentoId' => $departamento->id,
                        'departamento' => $departamento->codigo,
                        'conceptoId' => $concepto->id,
                        'concepto' => $concepto->codigo,
                    ], $calculo['warning'], $calculo['message']);
                    continue;
                }
                $owners = $this->owners($departamento->id, $periodo);
                $item = [
                    'departamentoId' => $departamento->id,
                    'departamento' => $departamento->codigo,
                    'conceptoId' => $concepto->id,
                    'concepto' => $concepto->codigo,
                    'formaCalculo' => $concepto->forma_calculo->value,
                    'tarifaId' => $tarifa->id,
                    'lecturaConsumoId' => $calculo['lectura']['id'] ?? null,
                    'tarifa' => $tarifa->valor ?? $tarifa->porcentaje,
                    'base' => $calculo['base'],
                    'valor' => $calculo['valor'],
                    'descripcion' => $concepto->nombre.' · '.$periodo->format('m/Y'),
                    'propietarioId' => $owners->count() === 1 ? $owners->first()['id'] : null,
                    'metadata' => [
                        'concepto' => ['id' => $concepto->id, 'codigo' => $concepto->codigo, 'nombre' => $concepto->nombre, 'tipo' => $concepto->tipo->value, 'periodicidad' => $concepto->periodicidad->value, 'formaCalculo' => $concepto->forma_calculo->value],
                        'tarifa' => ['id' => $tarifa->id, 'valor' => $tarifa->valor, 'porcentaje' => $tarifa->porcentaje, 'montoTotal' => $tarifa->monto_total, 'numeroCuotas' => $tarifa->numero_cuotas, 'unidad' => $tarifa->unidad, 'baseCalculo' => $tarifa->base_calculo?->value, 'fechaInicio' => $tarifa->fecha_inicio->format('Y-m-d')],
                        'departamento' => ['id' => $departamento->id, 'codigo' => $departamento->codigo, 'alicuota' => $departamento->alicuota],
                        'propietarios' => $owners->all(),
                        'calculo' => $calculo,
                    ],
                ];
                if ($this->automaticExists($edificioId, $item, $periodo, $lock)) {
                    $omitidos++;
                    $advertencias[] = $this->warning($item, 'CARGO_DUPLICADO', 'Ya existe un cargo automático para este período.');
                    continue;
                }
                $cargos[] = $item;
                $total = bcadd($total, $item['valor'], 4);
            }
        }

        return [
            'periodo' => $periodo->format('Y-m'),
            'cargos' => $cargos,
            'cantidadCargos' => count($cargos),
            'cantidadOmitidos' => $omitidos,
            'totalValor' => $total,
            'advertencias' => $advertencias,
        ];
    }

    private function authorizedEdificio(
        ?string $userId,
        string $edificioId,
        PermisoEdificio $permission,
        bool $lock = false,
    ): EdificioEloquentModel
    {
        $query = EdificioEloquentModel::query();
        if ($userId !== null) {
            $query->whereKey($this->access->buildingIds($userId, $permission));
        } else {
            $query->where('estado', EstadoEstructura::ACTIVO->value);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    private function tarifaVigente(string $edificioId, string $conceptoId, CarbonImmutable $periodo, bool $lock): ?TarifaConceptoEloquentModel
    {
        $query = TarifaConceptoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('concepto_cobro_id', $conceptoId)
            ->whereDate('fecha_inicio', '<=', $periodo->format('Y-m-d'))
            ->where(static fn (Builder $query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>', $periodo->format('Y-m-d')))
            ->orderByDesc('fecha_inicio');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return Collection<int, DepartamentoEloquentModel> */
    private function departamentosAlcance(string $edificioId, TarifaConceptoEloquentModel $tarifa, bool $lock): Collection
    {
        $query = DepartamentoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('estado', EstadoEstructura::ACTIVO->value)
            ->orderBy('id');
        if ($tarifa->alcance === AlcanceTarifa::DEPARTAMENTOS_ESPECIFICOS) {
            $ids = $tarifa->departamentos()->pluck('departamentos.id')->all();
            $query->whereIn('id', $ids);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->sortBy('codigo')->values();
    }

    private function activeDepartmentCount(string $edificioId, bool $lock): int
    {
        $query = DepartamentoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('estado', EstadoEstructura::ACTIVO->value);
        if ($lock) {
            return $query->lockForUpdate()->get()->count();
        }

        return $query->count();
    }

    /** @return array<string, mixed> */
    private function calculate(
        ConceptoCobroEloquentModel $concepto,
        TarifaConceptoEloquentModel $tarifa,
        DepartamentoEloquentModel $departamento,
        CarbonImmutable $periodo,
        bool $lock,
    ): array
    {
        if ($concepto->forma_calculo === FormaCalculoCobro::MANUAL) {
            return ['valor' => '0.0000', 'base' => null, 'warning' => 'CONCEPTO_MANUAL', 'message' => 'Los conceptos manuales no se generan automáticamente.', 'cuota' => null];
        }
        if ($concepto->forma_calculo === FormaCalculoCobro::POR_CONSUMO) {
            $query = LecturaConsumoEloquentModel::query()
                ->where('edificio_id', $concepto->edificio_id)
                ->where('departamento_id', $departamento->id)
                ->where('concepto_cobro_id', $concepto->id)
                ->whereDate('periodo', $periodo->format('Y-m-d'));
            if ($lock) {
                $query->lockForUpdate();
            }
            $lectura = $query->first();
            if ($lectura === null) {
                return ['valor' => '0.0000', 'base' => null, 'warning' => 'CONSUMO_NO_DISPONIBLE', 'message' => 'No existe una lectura de consumo para el período.', 'cuota' => null];
            }
            if ($tarifa->valor === null || $tarifa->unidad === null) {
                return ['valor' => '0.0000', 'base' => $lectura->consumo, 'warning' => 'TARIFA_INCOMPLETA', 'message' => 'La tarifa no contiene el precio y la unidad requeridos.', 'cuota' => null];
            }
            if (trim($tarifa->unidad) !== $lectura->unidad) {
                return ['valor' => '0.0000', 'base' => $lectura->consumo, 'warning' => 'UNIDAD_CONSUMO_INCONSISTENTE', 'message' => 'La unidad de la lectura no coincide con la tarifa del período.', 'cuota' => null];
            }
            $valor = $this->roundMoney(bcmul($lectura->consumo, $tarifa->valor, 8));
            $snapshot = [
                'id' => $lectura->id,
                'periodo' => $lectura->periodo->format('Y-m'),
                'fechaLectura' => $lectura->fecha_lectura->format('Y-m-d'),
                'lecturaAnterior' => $lectura->lectura_anterior,
                'lecturaActual' => $lectura->lectura_actual,
                'consumo' => $lectura->consumo,
                'unidad' => $lectura->unidad,
            ];
            if ($this->moneyOutOfRange($valor)) {
                return ['valor' => '0.0000', 'base' => $lectura->consumo, 'warning' => 'VALOR_FUERA_DE_RANGO', 'message' => 'El cálculo supera la precisión monetaria permitida.', 'cuota' => null, 'lectura' => $snapshot];
            }
            if (bccomp($valor, '0.0000', 4) === 0) {
                return ['valor' => '0.0000', 'base' => $lectura->consumo, 'warning' => 'VALOR_CERO', 'message' => 'Los cargos automáticos con valor cero se omiten.', 'cuota' => null, 'lectura' => $snapshot];
            }

            return ['valor' => $valor, 'base' => $lectura->consumo, 'warning' => null, 'message' => null, 'cuota' => null, 'lectura' => $snapshot];
        }
        if ($concepto->forma_calculo === FormaCalculoCobro::PORCENTAJE) {
            if ($tarifa->porcentaje === null || $tarifa->base_calculo === null) {
                return ['valor' => '0.0000', 'base' => null, 'warning' => 'BASE_PORCENTAJE_NO_DISPONIBLE', 'message' => 'La tarifa porcentual no define porcentaje y base de cálculo.', 'cuota' => null];
            }
            $base = $this->percentageBase($tarifa->base_calculo, $departamento, $periodo, $lock);
            $valor = $this->roundMoney(bcdiv(bcmul($base, $tarifa->porcentaje, 10), '100', 8));
            if ($this->moneyOutOfRange($valor)) {
                return ['valor' => '0.0000', 'base' => $base, 'warning' => 'VALOR_FUERA_DE_RANGO', 'message' => 'El cálculo supera la precisión monetaria permitida.', 'cuota' => null, 'baseCalculo' => $tarifa->base_calculo->value, 'fechaCorte' => $periodo->format('Y-m-d')];
            }
            if (bccomp($valor, '0.0000', 4) === 0) {
                return ['valor' => '0.0000', 'base' => $base, 'warning' => 'VALOR_CERO', 'message' => 'Los cargos automáticos con valor cero se omiten.', 'cuota' => null, 'baseCalculo' => $tarifa->base_calculo->value, 'fechaCorte' => $periodo->format('Y-m-d')];
            }

            return ['valor' => $valor, 'base' => $base, 'warning' => null, 'message' => null, 'cuota' => null, 'baseCalculo' => $tarifa->base_calculo->value, 'fechaCorte' => $periodo->format('Y-m-d')];
        }

        $valor = $tarifa->valor;
        if ($valor === null) {
            return ['valor' => '0.0000', 'base' => null, 'warning' => 'TARIFA_INCOMPLETA', 'message' => 'La tarifa no contiene el valor requerido.', 'cuota' => null];
        }
        $base = null;
        $cuota = null;
        if ($concepto->tipo === TipoConceptoCobro::EXTRAORDINARIO && $tarifa->monto_total !== null && $tarifa->numero_cuotas !== null) {
            $cuota = $this->installmentIndex($periodo, $tarifa->fecha_inicio, $concepto->periodicidad);
            if ($cuota === null || $cuota >= $tarifa->numero_cuotas) {
                return ['valor' => '0.0000', 'base' => $tarifa->monto_total, 'warning' => 'CUOTAS_COMPLETADAS', 'message' => 'La tarifa extraordinaria ya completó sus cuotas configuradas.', 'cuota' => $cuota];
            }
            $valor = $this->installmentValue($tarifa->monto_total, $tarifa->numero_cuotas, $cuota);
            $base = $tarifa->monto_total;
        }
        if ($concepto->forma_calculo === FormaCalculoCobro::POR_ALICUOTA) {
            $base ??= $valor;
            $valor = $this->roundMoney(bcdiv(bcmul($valor, $departamento->alicuota, 10), '100', 8));
        }
        if ($this->moneyOutOfRange($valor)) {
            return ['valor' => '0.0000', 'base' => $base, 'warning' => 'VALOR_FUERA_DE_RANGO', 'message' => 'El cálculo supera la precisión monetaria permitida.', 'cuota' => $cuota];
        }
        if (bccomp($valor, '0.0000', 4) === 0) {
            return ['valor' => '0.0000', 'base' => $base, 'warning' => 'VALOR_CERO', 'message' => 'Los cargos automáticos con valor cero se omiten.', 'cuota' => $cuota];
        }

        return ['valor' => $valor, 'base' => $base, 'warning' => null, 'message' => null, 'cuota' => $cuota];
    }

    private function percentageBase(
        BaseCalculoInteres $baseCalculo,
        DepartamentoEloquentModel $departamento,
        CarbonImmutable $periodo,
        bool $lock,
    ): string {
        $query = CargoEloquentModel::query()
            ->where('edificio_id', $departamento->edificio_id)
            ->where('departamento_id', $departamento->id)
            ->whereDate('fecha_emision', '<', $periodo->format('Y-m-d'))
            ->where(static fn (Builder $query) => $query
                ->where('estado', '!=', EstadoCargo::ANULADO->value)
                ->orWhere('anulado_at', '>=', $periodo))
            ->with(['concepto', 'aplicacionesPago.pago'])
            ->orderBy('id');
        if (in_array($baseCalculo, [BaseCalculoInteres::SALDO_VENCIDO, BaseCalculoInteres::CAPITAL_VENCIDO], true)) {
            $query->whereDate('fecha_vencimiento', '<', $periodo->format('Y-m-d'));
        }
        if ($baseCalculo === BaseCalculoInteres::CAPITAL_VENCIDO) {
            $query->whereHas('concepto', static fn (Builder $query) => $query
                ->where('tipo', '!=', TipoConceptoCobro::INTERES->value));
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->reduce(function (string $total, CargoEloquentModel $cargo) use ($periodo): string {
            $saldoAlCorte = $cargo->valor_original;
            foreach ($cargo->aplicacionesPago as $aplicacion) {
                $pago = $aplicacion->pago;
                if ($pago !== null
                    && $pago->fecha_pago->lessThan($periodo)
                    && $aplicacion->created_at?->lessThan($periodo)
                    && ($pago->estado === EstadoPago::REGISTRADO
                        || ($pago->estado === EstadoPago::ANULADO
                            && $pago->anulado_at !== null
                            && ! $pago->anulado_at->lessThan($periodo)))) {
                    $saldoAlCorte = bcsub($saldoAlCorte, $aplicacion->monto_aplicado, 4);
                }
            }

            return bccomp($saldoAlCorte, '0.0000', 4) > 0
                ? bcadd($total, $saldoAlCorte, 4)
                : $total;
        }, '0.0000');
    }

    /** @return Collection<int, array{id: string, nombre: string, identificacion: string, porcentaje: string}> */
    private function owners(string $departamentoId, CarbonImmutable $fecha): Collection
    {
        return DepartamentoPropietarioEloquentModel::query()
            ->where('departamento_id', $departamentoId)
            ->whereDate('fecha_inicio', '<=', $fecha->format('Y-m-d'))
            ->where(static fn (Builder $query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>', $fecha->format('Y-m-d')))
            ->orderBy('propietario_id')
            ->get()
            ->map(static fn (DepartamentoPropietarioEloquentModel $owner): array => [
                'id' => $owner->propietario_id,
                'nombre' => $owner->nombre_propietario,
                'identificacion' => $owner->identificacion_snapshot,
                'porcentaje' => $owner->porcentaje,
            ]);
    }

    private function isDue(PeriodicidadCobro $periodicidad, CarbonImmutable $periodo, CarbonImmutable $inicio): bool
    {
        $months = (($periodo->year - $inicio->year) * 12) + $periodo->month - $inicio->month;
        if ($months < 0) {
            return false;
        }

        return match ($periodicidad) {
            PeriodicidadCobro::MENSUAL => true,
            PeriodicidadCobro::TRIMESTRAL => $months % 3 === 0,
            PeriodicidadCobro::SEMESTRAL => $months % 6 === 0,
            PeriodicidadCobro::ANUAL => $months % 12 === 0,
            PeriodicidadCobro::UNICO => $months === 0,
            PeriodicidadCobro::MANUAL => false,
        };
    }

    private function installmentIndex(CarbonImmutable $periodo, CarbonImmutable $inicio, PeriodicidadCobro $periodicidad): ?int
    {
        $months = (($periodo->year - $inicio->year) * 12) + $periodo->month - $inicio->month;
        if ($months < 0) {
            return null;
        }
        $step = match ($periodicidad) {
            PeriodicidadCobro::MENSUAL => 1,
            PeriodicidadCobro::TRIMESTRAL => 3,
            PeriodicidadCobro::SEMESTRAL => 6,
            PeriodicidadCobro::ANUAL => 12,
            default => 1,
        };
        if ($months % $step !== 0) {
            return null;
        }

        return intdiv($months, $step);
    }

    private function installmentValue(string $total, int $cuotas, int $index): string
    {
        $base = bcdiv($total, (string) $cuotas, 4);
        $remainder = bcsub($total, bcmul($base, (string) $cuotas, 4), 4);
        $extra = (int) bcdiv($remainder, '0.0001', 0);

        return $index < $extra ? bcadd($base, '0.0001', 4) : $base;
    }

    private function automaticExists(string $edificioId, array $item, CarbonImmutable $periodo, bool $lock = false): bool
    {
        $query = CargoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('departamento_id', $item['departamentoId'])
            ->where('concepto_cobro_id', $item['conceptoId'])
            ->where('origen', OrigenCargo::AUTOMATICO->value)
            ->whereDate('periodo', $periodo->format('Y-m-d'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    private function automaticReference(string $edificioId, array $item, CarbonImmutable $periodo): string
    {
        return implode(':', ['automatico', $edificioId, $item['departamentoId'], $item['conceptoId'], $periodo->format('Y-m')]);
    }

    /** @return array<string, mixed> */
    private function serializeCargo(CargoEloquentModel $cargo, bool $detail = false): array
    {
        return [
            'id' => $cargo->id,
            'edificioId' => $cargo->edificio_id,
            'departamentoId' => $cargo->departamento_id,
            'departamento' => $cargo->departamento?->codigo,
            'conceptoId' => $cargo->concepto_cobro_id,
            'concepto' => $cargo->concepto?->nombre,
            'codigoConcepto' => $cargo->concepto?->codigo,
            'tarifaId' => $cargo->tarifa_id,
            'lecturaConsumoId' => $cargo->lectura_consumo_id,
            'periodo' => $cargo->periodo->format('Y-m'),
            'fechaEmision' => $cargo->fecha_emision->format('Y-m-d'),
            'fechaVencimiento' => $cargo->fecha_vencimiento->format('Y-m-d'),
            'descripcion' => $cargo->descripcion,
            'valorOriginal' => $cargo->valor_original,
            'saldo' => $cargo->saldo,
            'estado' => $cargo->estado->value,
            'origen' => $cargo->origen->value,
            'loteId' => $cargo->lote_generacion_id,
            'referenciaGeneracion' => $cargo->referencia_generacion,
            'anuladoAt' => $cargo->anulado_at?->toISOString(),
            'motivoAnulacion' => $cargo->motivo_anulacion,
            'snapshot' => $detail ? $cargo->metadata : null,
            'aplicacionesPago' => $detail ? $cargo->aplicacionesPago->map(static fn (\Src\Finanzas\Infrastructure\Models\AplicacionPagoEloquentModel $aplicacion): array => [
                'pagoId' => $aplicacion->pago_id,
                'numeroPago' => $aplicacion->pago?->numero,
                'fechaPago' => $aplicacion->pago?->fecha_pago?->format('Y-m-d'),
                'valorAplicado' => $aplicacion->monto_aplicado,
                'estadoPago' => $aplicacion->pago?->estado->value,
            ])->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function warning(array $item, string $code, string $message): array
    {
        return ['codigo' => $code, 'mensaje' => $message, ...$item];
    }

    private function period(mixed $value): CarbonImmutable
    {
        $value = (string) $value;
        $date = CarbonImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw ValidationException::withMessages(['periodo' => 'El período debe tener formato YYYY-MM.']);
        }

        return $date;
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

    private function money(mixed $value, string $field, bool $required = false): ?string
    {
        if ($value === null || $value === '') {
            if (! $required) {
                return null;
            }
            throw ValidationException::withMessages([$field => 'El valor es obligatorio.']);
        }
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'El valor debe ser decimal no negativo con hasta cuatro decimales.']);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 10) {
            throw ValidationException::withMessages([$field => 'El valor supera la precisión permitida.']);
        }

        return $integer.'.'.str_pad($fraction, 4, '0');
    }

    private function roundMoney(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }

    private function moneyOutOfRange(string $value): bool
    {
        return bccomp($value, self::MAX_MONEY, 4) > 0;
    }
}
