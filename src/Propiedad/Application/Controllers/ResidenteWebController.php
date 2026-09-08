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
use Src\Propiedad\Application\Actions\ChangeResidenteStatusAction;
use Src\Propiedad\Application\Actions\CreateResidenteAction;
use Src\Propiedad\Application\Actions\GetResidenteAction;
use Src\Propiedad\Application\Actions\GetResidenteOptionsAction;
use Src\Propiedad\Application\Actions\ListResidentesAction;
use Src\Propiedad\Application\Actions\UpdateResidenteAction;
use Src\Propiedad\Domain\Enums\EstadoResidente;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Requests\ChangeResidenteStatusRequest;
use Src\Propiedad\Infrastructure\Requests\SaveResidenteRequest;

final class ResidenteWebController extends Controller
{
    public function __construct(
        private readonly ListResidentesAction $listResidentes,
        private readonly GetResidenteAction $getResidente,
        private readonly GetResidenteOptionsAction $getOptions,
        private readonly CreateResidenteAction $createResidente,
        private readonly UpdateResidenteAction $updateResidente,
        private readonly ChangeResidenteStatusAction $changeStatus,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ResidenteEloquentModel::class);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoResidente::class)],
            'edificio_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $userId = (string) $request->user()->getAuthIdentifier();
        $result = $this->listResidentes->execute($userId, $filters);

        return Inertia::render('Residente/index', [
            'residentes' => [
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
        Gate::authorize('create', ResidenteEloquentModel::class);

        return Inertia::render('Residente/create', [
            'edificios' => $this->getOptions->buildings((string) $request->user()->getAuthIdentifier(), true),
            'edificioSeleccionado' => $request->query('edificio'),
        ]);
    }

    public function store(SaveResidenteRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->createResidente->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()
            ->route('residentes.show', $created['id'])
            ->with('success', 'Residente creado exitosamente.');
    }

    public function show(Request $request, ResidenteEloquentModel $residente): Response
    {
        Gate::authorize('view', $residente);

        return Inertia::render('Residente/show', [
            'residente' => $this->getResidente->execute(
                (string) $request->user()->getAuthIdentifier(),
                $residente->id,
            ),
        ]);
    }

    public function edit(Request $request, ResidenteEloquentModel $residente): Response
    {
        Gate::authorize('update', $residente);

        return Inertia::render('Residente/edit', [
            'residente' => $this->getResidente->execute(
                (string) $request->user()->getAuthIdentifier(),
                $residente->id,
            ),
        ]);
    }

    public function update(SaveResidenteRequest $request, ResidenteEloquentModel $residente): RedirectResponse
    {
        $this->updateResidente->execute(
            (string) $request->user()->getAuthIdentifier(),
            $residente->id,
            $request->validated(),
        );

        return redirect()
            ->route('residentes.show', $residente->id)
            ->with('success', 'Residente actualizado exitosamente.');
    }

    public function changeStatus(
        ChangeResidenteStatusRequest $request,
        ResidenteEloquentModel $residente,
    ): RedirectResponse {
        $this->changeStatus->execute(
            (string) $request->user()->getAuthIdentifier(),
            $residente->id,
            EstadoResidente::from($request->validated('estado')),
        );

        return back()->with('success', 'Estado del residente actualizado exitosamente.');
    }
}
