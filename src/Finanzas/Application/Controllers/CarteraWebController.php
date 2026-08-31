<?php

namespace Src\Finanzas\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;
use Src\Finanzas\Application\Actions\GetEstadoCuentaAction;
use Src\Finanzas\Application\Actions\GetPagoOptionsAction;
use Src\Finanzas\Application\Actions\PaginateCarteraAction;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;

final class CarteraWebController extends Controller
{
    public function __construct(private readonly PaginateCarteraAction $paginate, private readonly GetEstadoCuentaAction $statement, private readonly GetPagoOptionsAction $options) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'], 'departamento_id' => ['nullable', 'uuid'], 'piso_id' => ['nullable', 'uuid'], 'torre_id' => ['nullable', 'uuid'], 'propietario_id' => ['nullable', 'uuid'],
            'buscar' => ['nullable', 'string', 'max:100'], 'estado_departamento' => ['nullable', 'in:activo,inactivo'], 'situacion' => ['nullable', 'in:con_deuda,con_favor,vencidos'], 'estado' => ['nullable', 'in:al_dia,moroso,saldo_a_favor'],
            'concepto_id' => ['nullable', 'uuid'], 'periodo' => ['nullable', 'date_format:Y-m'], 'antiguedad' => ['nullable', 'in:1_a_30,31_a_60,61_a_90,mas_de_90'], 'fecha' => ['nullable', 'date_format:Y-m-d'], 'saldo_min' => ['nullable', 'decimal:0,4'], 'saldo_max' => ['nullable', 'decimal:0,4', 'gte:saldo_min'], 'orden' => ['nullable', 'in:neto_desc,vencido_desc,codigo_asc'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $userId = (string) $request->user()->getAuthIdentifier();
        $result = $this->paginate->execute($userId, $filters);
        $options = $this->options->execute($userId);
        $edificioIds = collect($options['edificios'])->pluck('id')->all();

        return Inertia::render('Cartera/index', [
            'cartera' => ['data' => $result['items'], 'meta' => ['total' => $result['total'], 'currentPage' => $result['currentPage'], 'lastPage' => $result['lastPage'], 'perPage' => $result['perPage']], 'summary' => $result['summary']],
            'filters' => $filters,
            ...$options,
            'torres' => TorreEloquentModel::query()->whereIn('edificio_id', $edificioIds)->orderBy('nombre')->get(['id', 'edificio_id', 'nombre'])->map(static fn (TorreEloquentModel $torre): array => ['id' => $torre->id, 'edificioId' => $torre->edificio_id, 'nombre' => $torre->nombre])->all(),
            'pisos' => PisoEloquentModel::query()->whereIn('edificio_id', $edificioIds)->orderBy('orden')->get(['id', 'edificio_id', 'torre_id', 'numero', 'nombre'])->map(static fn (PisoEloquentModel $piso): array => ['id' => $piso->id, 'edificioId' => $piso->edificio_id, 'torreId' => $piso->torre_id, 'nombre' => $piso->nombre ?? 'Piso '.$piso->numero])->all(),
            'conceptos' => ConceptoCobroEloquentModel::query()->whereIn('edificio_id', $edificioIds)->orderBy('nombre')->get(['id', 'edificio_id', 'nombre'])->map(static fn (ConceptoCobroEloquentModel $concepto): array => ['id' => $concepto->id, 'edificioId' => $concepto->edificio_id, 'nombre' => $concepto->nombre])->all(),
        ]);
    }

    public function show(Request $request, EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento): Response
    {
        Gate::authorize('view', $edificio);
        $filters = $request->validate(['fecha_desde' => ['nullable', 'date_format:Y-m-d'], 'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde']]);

        return Inertia::render('Cartera/show', ['estadoCuenta' => $this->statement->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $departamento->id, $filters)]);
    }
}
