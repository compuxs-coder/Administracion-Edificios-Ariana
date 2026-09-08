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
use Src\Finanzas\Domain\Contracts\CarteraReadRepositoryInterface;
use Src\Finanzas\Domain\Enums\EstadoCargo;
use Src\Finanzas\Domain\Enums\EstadoPago;
use Src\Finanzas\Infrastructure\Models\AplicacionPagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;

final class EloquentCarteraReadRepository implements CarteraReadRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginate(string $userId, array $filters): array
    {
        $fecha = $this->date($filters['fecha'] ?? CarbonImmutable::today()->format('Y-m-d'), 'fecha');
        $query = $this->baseQuery($userId, $filters, $fecha);
        $this->derivedFilters($query, $filters, $fecha);
        $summary = $this->summary(clone $query);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $this->applyOrder($query, (string) ($filters['orden'] ?? 'neto_desc'));
        $paginator = $query->with(['edificio', 'piso.torre'])->paginate($perPage, ['d.*'], 'page', (int) ($filters['page'] ?? 1));
        $owners = $this->ownersFor($paginator->getCollection()->pluck('id')->all(), $fecha);
        $items = $paginator->getCollection()->map(fn (DepartamentoEloquentModel $departamento): array => $this->serializeRow($departamento, $owners->get($departamento->id, collect()), $fecha))->all();

        return [
            'items' => $items,
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'summary' => $summary,
        ];
    }

    public function statement(string $userId, string $edificioId, string $departamentoId, array $filters): array
    {
        $desde = $this->date($filters['fecha_desde'] ?? CarbonImmutable::today()->startOfMonth()->format('Y-m-d'), 'fecha_desde');
        $hasta = $this->date($filters['fecha_hasta'] ?? CarbonImmutable::today()->format('Y-m-d'), 'fecha_hasta');
        if ($hasta->lessThan($desde)) {
            throw ValidationException::withMessages(['fecha_hasta' => 'La fecha final no puede ser anterior a la fecha inicial.']);
        }
        $this->authorizedBuilding($userId, $edificioId);
        $departamento = DepartamentoEloquentModel::query()->where('edificio_id', $edificioId)->with('edificio')->findOrFail($departamentoId);
        $movimientos = collect();
        $cargos = CargoEloquentModel::query()->where('edificio_id', $edificioId)->where('departamento_id', $departamentoId)->with('concepto')->get();
        foreach ($cargos as $cargo) {
            $movimientos->push($this->movement($cargo->fecha_emision, 'cargo', $cargo->id, $cargo->descripcion, $cargo->valor_original, '0.0000', $cargo->created_at, ['cargoId' => $cargo->id, 'concepto' => $cargo->concepto?->nombre, 'periodo' => $cargo->periodo->format('Y-m')]));
            if ($cargo->estado === EstadoCargo::ANULADO && $cargo->anulado_at !== null) {
                $movimientos->push($this->movement($cargo->anulado_at->toImmutable()->startOfDay(), 'anulacion_cargo', $cargo->id, 'Anulación · '.$cargo->descripcion, '0.0000', $cargo->valor_original, $cargo->anulado_at, ['cargoId' => $cargo->id]));
            }
        }
        $pagos = PagoEloquentModel::query()->where('edificio_id', $edificioId)->where('departamento_id', $departamentoId)->with(['aplicaciones.cargo.concepto'])->get();
        foreach ($pagos as $pago) {
            $movimientos->push($this->movement($pago->fecha_pago, 'pago', $pago->id, $pago->numero, '0.0000', $pago->monto_recibido, $pago->created_at, ['pagoId' => $pago->id, 'numeroPago' => $pago->numero, 'aplicaciones' => $pago->aplicaciones->map(static fn (AplicacionPagoEloquentModel $aplicacion): array => ['cargoId' => $aplicacion->cargo_id, 'concepto' => $aplicacion->cargo?->concepto?->nombre ?? $aplicacion->cargo?->descripcion, 'periodo' => $aplicacion->cargo?->periodo?->format('Y-m'), 'montoAplicado' => $aplicacion->monto_aplicado])->all()]));
            if ($pago->estado === EstadoPago::ANULADO && $pago->anulado_at !== null) {
                $movimientos->push($this->movement($pago->anulado_at->toImmutable()->startOfDay(), 'anulacion_pago', $pago->id, 'Anulación · '.$pago->numero, $pago->monto_recibido, '0.0000', $pago->anulado_at, ['pagoId' => $pago->id]));
            }
        }
        $ordered = $movimientos->sortBy(fn (array $item): array => [$item['fecha'], $this->movementOrder($item['tipo']), $item['createdAt'] ?? '', $item['id']])->values();
        $saldoInicial = $ordered->filter(fn (array $item): bool => $item['fecha'] < $desde->format('Y-m-d'))->reduce(fn (string $saldo, array $item): string => bcadd($saldo, bcsub($item['debito'], $item['credito'], 4), 4), '0.0000');
        $saldo = $saldoInicial;
        $rango = $ordered->filter(fn (array $item): bool => $item['fecha'] >= $desde->format('Y-m-d') && $item['fecha'] <= $hasta->format('Y-m-d'))->map(function (array $item) use (&$saldo): array {
            $saldo = bcadd($saldo, bcsub($item['debito'], $item['credito'], 4), 4);
            $item['saldoAcumulado'] = $saldo;
            return $item;
        })->values();

        return [
            'edificio' => ['id' => $edificioId, 'nombre' => $departamento->edificio?->nombre],
            'departamento' => ['id' => $departamento->id, 'codigo' => $departamento->codigo, 'nombre' => $departamento->nombre, 'estado' => $departamento->estado->value],
            'periodo' => ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')],
            'propietarios' => $this->ownersFor([$departamentoId], $hasta)->get($departamentoId, collect())->all(),
            'saldoInicial' => $this->signed($saldoInicial),
            'movimientos' => $rango->all(),
            'saldoFinal' => $this->signed($saldo),
            'resumen' => [
                'debitos' => $rango->reduce(fn (string $total, array $item): string => bcadd($total, $item['debito'], 4), '0.0000'),
                'creditos' => $rango->reduce(fn (string $total, array $item): string => bcadd($total, $item['credito'], 4), '0.0000'),
            ],
        ];
    }

    private function baseQuery(string $userId, array $filters, CarbonImmutable $fecha): Builder
    {
        $cargos = (new CargoEloquentModel())->getTable();
        $pagos = (new PagoEloquentModel())->getTable();
        $aplicaciones = (new AplicacionPagoEloquentModel())->getTable();
        $creditsPerPayment = DB::table($pagos.' as p')
            ->leftJoin($aplicaciones.' as a', 'a.pago_id', '=', 'p.id')
            ->where('p.estado', EstadoPago::REGISTRADO->value)
            ->selectRaw('p.departamento_id, p.monto_recibido - COALESCE(SUM(a.monto_aplicado), 0) as saldo_favor')
            ->groupBy('p.id', 'p.departamento_id', 'p.monto_recibido');
        $credits = DB::query()->fromSub($creditsPerPayment, 'pf')->selectRaw('departamento_id, COALESCE(SUM(saldo_favor), 0) as saldo_favor')->groupBy('departamento_id');
        $lastPayments = DB::table($pagos)->where('estado', EstadoPago::REGISTRADO->value)->selectRaw('departamento_id, MAX(fecha_pago) as ultimo_pago')->groupBy('departamento_id');
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER);

        $query = DepartamentoEloquentModel::query()
            ->from((new DepartamentoEloquentModel())->getTable().' as d')
            ->whereIn('d.edificio_id', $buildingIds)
            ->leftJoin($cargos.' as c', function ($join): void {
                $join->on('c.departamento_id', '=', 'd.id')->where('c.estado', '!=', EstadoCargo::ANULADO->value);
            })
            ->leftJoinSub($credits, 'f', 'f.departamento_id', '=', 'd.id')
            ->leftJoinSub($lastPayments, 'u', 'u.departamento_id', '=', 'd.id')
            ->select('d.*')
            ->selectRaw('COALESCE(SUM(c.valor_original), 0) as cartera_bruta')
            ->selectRaw('COALESCE(SUM(c.valor_original - c.saldo), 0) as pagos_aplicados')
            ->selectRaw('COALESCE(SUM(c.saldo), 0) as saldo_pendiente')
            ->selectRaw('COALESCE(SUM(CASE WHEN c.saldo > 0 AND c.fecha_vencimiento < ? THEN c.saldo ELSE 0 END), 0) as saldo_vencido', [$fecha->format('Y-m-d')])
            ->selectRaw('MIN(CASE WHEN c.saldo > 0 AND c.fecha_vencimiento < ? THEN c.fecha_vencimiento ELSE NULL END) as vencimiento_mas_antiguo', [$fecha->format('Y-m-d')])
            ->selectRaw('COALESCE(f.saldo_favor, 0) as saldo_favor')
            ->selectRaw('u.ultimo_pago')
            ->groupBy('d.id', 'f.saldo_favor', 'u.ultimo_pago');
        foreach (['edificio_id', 'departamento_id'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where('d.'.$field, $filters[$field]);
            }
        }
        if (($filters['estado_departamento'] ?? null) !== null) {
            $query->where('d.estado', $filters['estado_departamento']);
        }
        if (($filters['piso_id'] ?? null) !== null) {
            $query->where('d.piso_id', $filters['piso_id']);
        }
        if (($filters['torre_id'] ?? null) !== null) {
            $query->whereHas('piso', static fn (Builder $query) => $query->where('torre_id', $filters['torre_id']));
        }
        if (($filters['concepto_id'] ?? null) !== null) {
            $query->whereExists(function ($subquery) use ($cargos, $filters): void {
                $subquery->selectRaw('1')->from($cargos.' as cf')->whereColumn('cf.departamento_id', 'd.id')->where('cf.concepto_cobro_id', $filters['concepto_id'])->where('cf.estado', '!=', EstadoCargo::ANULADO->value)->where('cf.saldo', '>', 0);
            });
        }
        if (($filters['periodo'] ?? null) !== null) {
            $query->whereExists(function ($subquery) use ($cargos, $filters): void {
                $subquery->selectRaw('1')->from($cargos.' as cp')->whereColumn('cp.departamento_id', 'd.id')->whereDate('cp.periodo', $filters['periodo'].'-01')->where('cp.estado', '!=', EstadoCargo::ANULADO->value);
            });
        }
        if (($filters['propietario_id'] ?? null) !== null) {
            $titulares = (new DepartamentoPropietarioEloquentModel())->getTable();
            $query->whereExists(function ($subquery) use ($titulares, $filters, $fecha): void {
                $subquery->selectRaw('1')->from($titulares.' as t')->whereColumn('t.departamento_id', 'd.id')->whereDate('t.fecha_inicio', '<=', $fecha->format('Y-m-d'))->where(static fn ($nested) => $nested->whereNull('t.fecha_fin')->orWhereDate('t.fecha_fin', '>', $fecha->format('Y-m-d')));
                $subquery->where('t.propietario_id', $filters['propietario_id']);
            });
        }
        if (($filters['buscar'] ?? null) !== null) {
            $term = '%'.mb_strtolower((string) $filters['buscar']).'%';
            $titulares = (new DepartamentoPropietarioEloquentModel())->getTable();
            $query->where(function (Builder $nested) use ($term, $titulares, $fecha): void {
                $nested->whereRaw('LOWER(d.codigo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(d.nombre) LIKE ?', [$term])
                    ->orWhereExists(function ($subquery) use ($titulares, $term, $fecha): void {
                        $subquery->selectRaw('1')->from($titulares.' as t')->whereColumn('t.departamento_id', 'd.id')->whereDate('t.fecha_inicio', '<=', $fecha->format('Y-m-d'))->where(static fn ($dates) => $dates->whereNull('t.fecha_fin')->orWhereDate('t.fecha_fin', '>', $fecha->format('Y-m-d')))->where(function ($owner) use ($term): void { $owner->whereRaw('LOWER(t.nombre_propietario) LIKE ?', [$term])->orWhereRaw('LOWER(t.identificacion_snapshot) LIKE ?', [$term]); });
                    });
            });
        }

        return $query;
    }

    private function derivedFilters(Builder $query, array $filters, CarbonImmutable $fecha): void
    {
        $situacion = $filters['situacion'] ?? null;
        if ($situacion === 'con_deuda') $query->havingRaw('COALESCE(SUM(c.saldo), 0) > 0');
        if ($situacion === 'con_favor') $query->havingRaw('COALESCE(f.saldo_favor, 0) > 0');
        if ($situacion === 'vencidos' || ($filters['estado'] ?? null) === 'moroso') $query->havingRaw('COALESCE(SUM(CASE WHEN c.saldo > 0 AND c.fecha_vencimiento < ? THEN c.saldo ELSE 0 END), 0) > 0', [$fecha->format('Y-m-d')]);
        if (($filters['estado'] ?? null) === 'saldo_a_favor') $query->havingRaw('COALESCE(f.saldo_favor, 0) > COALESCE(SUM(c.saldo), 0)');
        if (($filters['estado'] ?? null) === 'al_dia') $query->havingRaw('COALESCE(SUM(CASE WHEN c.saldo > 0 AND c.fecha_vencimiento < ? THEN c.saldo ELSE 0 END), 0) = 0 AND COALESCE(f.saldo_favor, 0) <= COALESCE(SUM(c.saldo), 0)', [$fecha->format('Y-m-d')]);
        if (($filters['saldo_min'] ?? null) !== null) $query->havingRaw('(COALESCE(SUM(c.saldo), 0) - COALESCE(f.saldo_favor, 0)) >= ?', [$filters['saldo_min']]);
        if (($filters['saldo_max'] ?? null) !== null) $query->havingRaw('(COALESCE(SUM(c.saldo), 0) - COALESCE(f.saldo_favor, 0)) <= ?', [$filters['saldo_max']]);
        if (($filters['antiguedad'] ?? null) !== null) {
            $oldest = 'MIN(CASE WHEN c.saldo > 0 AND c.fecha_vencimiento < ? THEN c.fecha_vencimiento ELSE NULL END)';
            $bucket = $filters['antiguedad'];
            if ($bucket === '1_a_30') $query->havingRaw($oldest.' >= ? AND '.$oldest.' <= ?', [$fecha->format('Y-m-d'), $fecha->subDays(30)->format('Y-m-d'), $fecha->format('Y-m-d'), $fecha->subDay()->format('Y-m-d')]);
            if ($bucket === '31_a_60') $query->havingRaw($oldest.' >= ? AND '.$oldest.' <= ?', [$fecha->format('Y-m-d'), $fecha->subDays(60)->format('Y-m-d'), $fecha->format('Y-m-d'), $fecha->subDays(31)->format('Y-m-d')]);
            if ($bucket === '61_a_90') $query->havingRaw($oldest.' >= ? AND '.$oldest.' <= ?', [$fecha->format('Y-m-d'), $fecha->subDays(90)->format('Y-m-d'), $fecha->format('Y-m-d'), $fecha->subDays(61)->format('Y-m-d')]);
            if ($bucket === 'mas_de_90') $query->havingRaw($oldest.' < ?', [$fecha->format('Y-m-d'), $fecha->subDays(90)->format('Y-m-d')]);
        }
    }

    private function applyOrder(Builder $query, string $order): void
    {
        match ($order) {
            'vencido_desc' => $query->orderByDesc('saldo_vencido')->orderBy('d.codigo'),
            'codigo_asc' => $query->orderBy('d.codigo'),
            default => $query->orderByRaw('(COALESCE(SUM(c.saldo), 0) - COALESCE(f.saldo_favor, 0)) DESC')->orderBy('d.codigo'),
        };
    }

    /** @return array<string, string|int> */
    private function summary(Builder $query): array
    {
        $rows = DB::query()->fromSub($query->toBase(), 'cartera')->selectRaw('COUNT(*) as total, COALESCE(SUM(cartera_bruta),0) as bruto, COALESCE(SUM(pagos_aplicados),0) as pagado, COALESCE(SUM(saldo_pendiente),0) as pendiente, COALESCE(SUM(saldo_vencido),0) as vencido, COALESCE(SUM(saldo_favor),0) as favor, COALESCE(SUM(CASE WHEN saldo_vencido > 0 THEN 1 ELSE 0 END),0) as morosos, COALESCE(SUM(CASE WHEN saldo_pendiente > 0 THEN 1 ELSE 0 END),0) as con_deuda')->first();
        $total = (int) $rows->total;

        return ['totalCartera' => $this->decimal($rows->bruto), 'pagosAplicados' => $this->decimal($rows->pagado), 'saldoPendiente' => $this->decimal($rows->pendiente), 'saldoVencido' => $this->decimal($rows->vencido), 'saldoNoVencido' => $this->decimal(bcsub((string) $rows->pendiente, (string) $rows->vencido, 4)), 'saldoFavor' => $this->decimal($rows->favor), 'saldoNeto' => $this->decimal(bcsub((string) $rows->pendiente, (string) $rows->favor, 4)), 'departamentosAlDia' => $total - (int) $rows->morosos, 'departamentosConDeuda' => (int) $rows->con_deuda, 'morosidadPorcentaje' => $total === 0 ? '0.00' : number_format(((int) $rows->morosos / $total) * 100, 2, '.', '')];
    }

    /** @param list<string> $ids @return Collection<string, Collection<int, array<string, string>>> */
    private function ownersFor(array $ids, CarbonImmutable $fecha): Collection
    {
        if ($ids === []) return collect();
        return DepartamentoPropietarioEloquentModel::query()->whereIn('departamento_id', $ids)->whereDate('fecha_inicio', '<=', $fecha->format('Y-m-d'))->where(static fn (Builder $query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>', $fecha->format('Y-m-d')))->orderBy('nombre_propietario')->get()->groupBy('departamento_id')->map(static fn (Collection $titulares): Collection => $titulares->map(static fn (DepartamentoPropietarioEloquentModel $owner): array => ['id' => $owner->propietario_id, 'nombre' => $owner->nombre_propietario, 'identificacion' => $owner->identificacion_snapshot, 'porcentaje' => $owner->porcentaje]));
    }

    /** @param Collection<int, array<string, string>> $owners @return array<string, mixed> */
    private function serializeRow(DepartamentoEloquentModel $departamento, Collection $owners, CarbonImmutable $fecha): array
    {
        $bruto = $this->decimal($departamento->getAttribute('cartera_bruta'));
        $pagado = $this->decimal($departamento->getAttribute('pagos_aplicados'));
        $pendiente = $this->decimal($departamento->getAttribute('saldo_pendiente'));
        $vencido = $this->decimal($departamento->getAttribute('saldo_vencido'));
        $favor = $this->decimal($departamento->getAttribute('saldo_favor'));
        $neto = bcsub($pendiente, $favor, 4);
        $oldest = $departamento->getAttribute('vencimiento_mas_antiguo');
        $days = $oldest === null ? 0 : CarbonImmutable::parse((string) $oldest)->diffInDays($fecha);
        $estado = bccomp($vencido, '0.0000', 4) > 0 ? 'moroso' : (bccomp($neto, '0.0000', 4) < 0 ? 'saldo_a_favor' : 'al_dia');

        return ['edificioId' => $departamento->edificio_id, 'edificio' => $departamento->edificio?->nombre, 'torre' => $departamento->piso?->torre?->nombre, 'piso' => $departamento->piso?->nombre ?? $departamento->piso?->numero, 'departamentoId' => $departamento->id, 'departamento' => $departamento->codigo, 'nombreDepartamento' => $departamento->nombre, 'estadoDepartamento' => $departamento->estado->value, 'propietarios' => $owners->values()->all(), 'cargosTotales' => $bruto, 'pagosAplicados' => $pagado, 'saldoPendiente' => $pendiente, 'saldoVencido' => $vencido, 'saldoNoVencido' => bcsub($pendiente, $vencido, 4), 'saldoFavor' => $favor, 'saldoNeto' => $neto, 'saldoNetoDeudor' => bccomp($neto, '0.0000', 4) > 0 ? $neto : '0.0000', 'saldoNetoAcreedor' => bccomp($neto, '0.0000', 4) < 0 ? bcsub('0.0000', $neto, 4) : '0.0000', 'fechaVencimientoMasAntigua' => $oldest, 'diasAtraso' => $days, 'antiguedad' => $this->age($days), 'estado' => $estado, 'ultimoPago' => $departamento->getAttribute('ultimo_pago'), 'ultimaGestionCobro' => null];
    }

    /** @return array<string, mixed> */
    private function movement(CarbonImmutable $fecha, string $tipo, string $id, string $referencia, string $debito, string $credito, mixed $createdAt, array $metadata): array
    {
        return ['id' => $id.'-'.$tipo, 'fecha' => $fecha->format('Y-m-d'), 'tipo' => $tipo, 'referencia' => $referencia, 'debito' => $debito, 'credito' => $credito, 'createdAt' => $createdAt?->toISOString(), ...$metadata];
    }

    private function movementOrder(string $tipo): int { return ['cargo' => 1, 'pago' => 2, 'anulacion_cargo' => 3, 'anulacion_pago' => 4][$tipo] ?? 9; }
    private function age(int $days): string { return match (true) { $days <= 0 => 'al_dia', $days <= 30 => '1_a_30', $days <= 60 => '31_a_60', $days <= 90 => '61_a_90', default => 'mas_de_90' }; }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? '0'), '0', 4); }
    /** @return array{neto: string, deudor: string, acreedor: string} */
    private function signed(string $value): array { return ['neto' => $value, 'deudor' => bccomp($value, '0.0000', 4) > 0 ? $value : '0.0000', 'acreedor' => bccomp($value, '0.0000', 4) < 0 ? bcsub('0.0000', $value, 4) : '0.0000']; }
    private function date(mixed $value, string $field): CarbonImmutable { $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value); if ($date === false || $date->format('Y-m-d') !== $value) throw ValidationException::withMessages([$field => 'La fecha debe tener formato YYYY-MM-DD.']); return $date; }
    private function authorizedBuilding(string $userId, string $edificioId): void { EdificioEloquentModel::query()->whereKey($this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER))->findOrFail($edificioId); }
}
