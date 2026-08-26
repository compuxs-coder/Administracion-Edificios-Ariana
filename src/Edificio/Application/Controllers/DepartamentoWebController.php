<?php

namespace Src\Edificio\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Actions\ChangeDepartamentoStatusAction;
use Src\Edificio\Application\Actions\CreateDepartamentoAction;
use Src\Edificio\Application\Actions\GetDepartamentoAction;
use Src\Edificio\Application\Actions\GetDepartamentoOptionsAction;
use Src\Edificio\Application\Actions\ListDepartamentosAction;
use Src\Edificio\Application\Actions\UpdateDepartamentoAction;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Requests\ChangeEstructuraStatusRequest;
use Src\Edificio\Infrastructure\Requests\SaveDepartamentoRequest;
use Src\Propiedad\Application\Actions\GetDepartamentoPropiedadAction;

final class DepartamentoWebController extends Controller
{
    public function __construct(
        private readonly ListDepartamentosAction $listDepartamentos,
        private readonly GetDepartamentoAction $getDepartamento,
        private readonly GetDepartamentoOptionsAction $getOptions,
        private readonly CreateDepartamentoAction $createDepartamento,
        private readonly UpdateDepartamentoAction $updateDepartamento,
        private readonly ChangeDepartamentoStatusAction $changeStatus,
        private readonly GetDepartamentoPropiedadAction $getPropiedad,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'torre_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoEstructura::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $result = $this->listDepartamentos->execute(
            (string) $request->user()->getAuthIdentifier(),
            $filters,
        );

        return Inertia::render('Departamento/index', [
            'departamentos' => [
                'data' => $result['items'],
                'meta' => [
                    'total' => $result['total'],
                    'currentPage' => $result['currentPage'],
                    'lastPage' => $result['lastPage'],
                    'perPage' => $result['perPage'],
                ],
            ],
            'filters' => $filters,
            'edificios' => $this->getOptions->execute((string) $request->user()->getAuthIdentifier()),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', EdificioEloquentModel::class);

        return Inertia::render('Departamento/create', [
            'edificios' => $this->getOptions->execute((string) $request->user()->getAuthIdentifier()),
            'edificioSeleccionado' => $request->query('edificio'),
        ]);
    }

    public function store(
        SaveDepartamentoRequest $request,
        EdificioEloquentModel $edificio,
    ): RedirectResponse {
        $this->createDepartamento->execute($edificio->id, $request->validated());

        return redirect()->route('departamentos.index')->with('success', 'Departamento creado exitosamente.');
    }

    public function edit(
        Request $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): Response {
        Gate::authorize('update', $edificio);

        return Inertia::render('Departamento/edit', [
            'departamento' => $this->getDepartamento->execute($edificio->id, $departamento),
            'edificios' => $this->getOptions->execute((string) $request->user()->getAuthIdentifier()),
        ]);
    }

    public function show(
        Request $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): Response {
        Gate::authorize('view', $edificio);
        $userId = (string) $request->user()->getAuthIdentifier();

        return Inertia::render('Departamento/show', [
            'departamento' => $this->getDepartamento->execute($edificio->id, $departamento),
            'propiedad' => $this->getPropiedad->execute($userId, $edificio->id, $departamento),
        ]);
    }

    public function update(
        SaveDepartamentoRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): RedirectResponse {
        $this->updateDepartamento->execute($edificio->id, $departamento, $request->validated());

        return redirect()->route('departamentos.index')->with('success', 'Departamento actualizado exitosamente.');
    }

    public function changeStatus(
        ChangeEstructuraStatusRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): RedirectResponse {
        $this->changeStatus->execute(
            $edificio->id,
            $departamento,
            EstadoEstructura::from($request->validated('estado')),
        );

        return back()->with('success', 'Estado del departamento actualizado exitosamente.');
    }
}
