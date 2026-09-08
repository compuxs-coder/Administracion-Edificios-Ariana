<?php

namespace Src\Finanzas\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Application\Actions\ChangeConceptoCobroStatusAction;
use Src\Finanzas\Application\Actions\CreateConceptoCobroAction;
use Src\Finanzas\Application\Actions\CreateTarifaConceptoAction;
use Src\Finanzas\Application\Actions\GetConceptoCobroAction;
use Src\Finanzas\Application\Actions\GetConceptoCobroOptionsAction;
use Src\Finanzas\Application\Actions\ListConceptosCobroAction;
use Src\Finanzas\Application\Actions\UpdateConceptoCobroAction;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\PeriodicidadCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Requests\ChangeConceptoCobroStatusRequest;
use Src\Finanzas\Infrastructure\Requests\CreateTarifaConceptoRequest;
use Src\Finanzas\Infrastructure\Requests\SaveConceptoCobroRequest;

final class ConceptoCobroWebController extends Controller
{
    public function __construct(
        private readonly ListConceptosCobroAction $listConceptos,
        private readonly GetConceptoCobroAction $getConcepto,
        private readonly GetConceptoCobroOptionsAction $getOptions,
        private readonly CreateConceptoCobroAction $createConcepto,
        private readonly UpdateConceptoCobroAction $updateConcepto,
        private readonly ChangeConceptoCobroStatusAction $changeStatus,
        private readonly CreateTarifaConceptoAction $createTarifa,
        private readonly AccesoEdificioService $access,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->allowsAny(
            (string) $request->user()->getAuthIdentifier(),
            PermisoEdificio::FINANZAS_VER,
        ), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'tipo' => ['nullable', Rule::enum(TipoConceptoCobro::class)],
            'periodicidad' => ['nullable', Rule::enum(PeriodicidadCobro::class)],
            'forma_calculo' => ['nullable', Rule::enum(FormaCalculoCobro::class)],
            'estado' => ['nullable', Rule::enum(EstadoConceptoCobro::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $userId = (string) $request->user()->getAuthIdentifier();
        $result = $this->listConceptos->execute($userId, $filters);

        return Inertia::render('Concepto/index', [
            'conceptos' => [
                'data' => $result['items'],
                'meta' => [
                    'total' => $result['total'],
                    'currentPage' => $result['currentPage'],
                    'lastPage' => $result['lastPage'],
                    'perPage' => $result['perPage'],
                ],
            ],
            'filters' => $filters,
            'edificios' => $this->getOptions->buildings($userId),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->access->allowsAny(
            (string) $request->user()->getAuthIdentifier(),
            PermisoEdificio::CONCEPTOS_GESTIONAR,
        ), 403);

        return Inertia::render('Concepto/create', [
            'edificios' => $this->getOptions->buildings(
                (string) $request->user()->getAuthIdentifier(),
                PermisoEdificio::CONCEPTOS_GESTIONAR,
            ),
            'edificioSeleccionado' => $request->query('edificio'),
        ]);
    }

    public function store(SaveConceptoCobroRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $concepto = $this->createConcepto->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()
            ->route('conceptos.show', [$edificio->id, $concepto['id']])
            ->with('success', 'Concepto de cobro creado exitosamente.');
    }

    public function show(
        Request $request,
        EdificioEloquentModel $edificio,
        ConceptoCobroEloquentModel $concepto,
    ): Response {
        Gate::authorize('access', [$edificio, PermisoEdificio::FINANZAS_VER]);

        return Inertia::render('Concepto/show', [
            'concepto' => $this->getConcepto->execute(
                (string) $request->user()->getAuthIdentifier(),
                $edificio->id,
                $concepto->id,
            ),
        ]);
    }

    public function edit(
        Request $request,
        EdificioEloquentModel $edificio,
        ConceptoCobroEloquentModel $concepto,
    ): Response {
        Gate::authorize('access', [$edificio, PermisoEdificio::CONCEPTOS_GESTIONAR]);

        return Inertia::render('Concepto/edit', [
            'concepto' => $this->getConcepto->execute(
                (string) $request->user()->getAuthIdentifier(),
                $edificio->id,
                $concepto->id,
            ),
        ]);
    }

    public function update(
        SaveConceptoCobroRequest $request,
        EdificioEloquentModel $edificio,
        ConceptoCobroEloquentModel $concepto,
    ): RedirectResponse {
        $this->updateConcepto->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $concepto->id,
            $request->validated(),
        );

        return redirect()
            ->route('conceptos.show', [$edificio->id, $concepto->id])
            ->with('success', 'Concepto de cobro actualizado exitosamente.');
    }

    public function changeStatus(
        ChangeConceptoCobroStatusRequest $request,
        EdificioEloquentModel $edificio,
        ConceptoCobroEloquentModel $concepto,
    ): RedirectResponse {
        $this->changeStatus->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $concepto->id,
            EstadoConceptoCobro::from($request->validated('estado')),
        );

        return back()->with('success', 'Estado del concepto actualizado exitosamente.');
    }

    public function storeTarifa(
        CreateTarifaConceptoRequest $request,
        EdificioEloquentModel $edificio,
        ConceptoCobroEloquentModel $concepto,
    ): RedirectResponse {
        $this->createTarifa->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $concepto->id,
            $request->validated(),
        );

        return back()->with('success', 'Nueva tarifa registrada y vigencia actualizada exitosamente.');
    }
}
