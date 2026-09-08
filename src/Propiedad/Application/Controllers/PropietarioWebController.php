<?php

namespace Src\Propiedad\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Application\Actions\ChangePropietarioStatusAction;
use Src\Propiedad\Application\Actions\CreatePropietarioAction;
use Src\Propiedad\Application\Actions\GetPropietarioAction;
use Src\Propiedad\Application\Actions\GetPropietarioOptionsAction;
use Src\Propiedad\Application\Actions\ListPropietariosAction;
use Src\Propiedad\Application\Actions\UpdatePropietarioAction;
use Src\Propiedad\Domain\Enums\EstadoPropietario;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Requests\ChangePropietarioStatusRequest;
use Src\Propiedad\Infrastructure\Requests\SavePropietarioRequest;

final class PropietarioWebController extends Controller
{
    public function __construct(
        private readonly ListPropietariosAction $listPropietarios,
        private readonly GetPropietarioAction $getPropietario,
        private readonly GetPropietarioOptionsAction $getOptions,
        private readonly CreatePropietarioAction $createPropietario,
        private readonly UpdatePropietarioAction $updatePropietario,
        private readonly ChangePropietarioStatusAction $changeStatus,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PropietarioEloquentModel::class);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'tipo_persona' => ['nullable', Rule::enum(TipoPersona::class)],
            'estado' => ['nullable', Rule::enum(EstadoPropietario::class)],
            'edificio_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $userId = (string) $request->user()->getAuthIdentifier();
        $result = $this->listPropietarios->execute($userId, $filters);

        return Inertia::render('Propietario/index', [
            'propietarios' => [
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
        Gate::authorize('create', PropietarioEloquentModel::class);

        return Inertia::render('Propietario/create', [
            'edificios' => $this->getOptions->buildings((string) $request->user()->getAuthIdentifier(), true),
            'edificioSeleccionado' => $request->query('edificio'),
        ]);
    }

    public function store(
        SavePropietarioRequest $request,
        EdificioEloquentModel $edificio,
    ): RedirectResponse {
        $created = $this->createPropietario->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()
            ->route('propietarios.show', $created['id'])
            ->with('success', 'Propietario creado exitosamente.');
    }

    public function show(Request $request, PropietarioEloquentModel $propietario): Response
    {
        Gate::authorize('view', $propietario);

        return Inertia::render('Propietario/show', [
            'propietario' => $this->getPropietario->execute(
                (string) $request->user()->getAuthIdentifier(),
                $propietario->id,
            ),
        ]);
    }

    public function edit(Request $request, PropietarioEloquentModel $propietario): Response
    {
        Gate::authorize('update', $propietario);

        return Inertia::render('Propietario/edit', [
            'propietario' => $this->getPropietario->execute(
                (string) $request->user()->getAuthIdentifier(),
                $propietario->id,
            ),
        ]);
    }

    public function update(
        SavePropietarioRequest $request,
        PropietarioEloquentModel $propietario,
    ): RedirectResponse {
        $this->updatePropietario->execute(
            (string) $request->user()->getAuthIdentifier(),
            $propietario->id,
            $request->validated(),
        );

        return redirect()
            ->route('propietarios.show', $propietario->id)
            ->with('success', 'Propietario actualizado exitosamente.');
    }

    public function changeStatus(
        ChangePropietarioStatusRequest $request,
        PropietarioEloquentModel $propietario,
    ): RedirectResponse {
        $this->changeStatus->execute(
            (string) $request->user()->getAuthIdentifier(),
            $propietario->id,
            EstadoPropietario::from($request->validated('estado')),
        );

        return back()->with('success', 'Estado del propietario actualizado exitosamente.');
    }
}
