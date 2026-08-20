<?php

namespace Src\Edificio\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Actions\ChangeEdificioStatusAction;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Application\Actions\GetEdificioAction;
use Src\Edificio\Application\Actions\ListEdificiosAction;
use Src\Edificio\Application\Actions\UpdateEdificioAction;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Requests\ChangeEdificioStatusRequest;
use Src\Edificio\Infrastructure\Requests\StoreEdificioRequest;
use Src\Edificio\Infrastructure\Requests\UpdateEdificioRequest;

final class EdificioWebController extends Controller
{
    public function __construct(
        private readonly ListEdificiosAction $listEdificios,
        private readonly CreateEdificioAction $createEdificio,
        private readonly GetEdificioAction $getEdificio,
        private readonly UpdateEdificioAction $updateEdificio,
        private readonly ChangeEdificioStatusAction $changeEdificioStatus,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);

        $search = trim((string) $request->query('buscar', ''));
        $result = $this->listEdificios->execute(
            userId: (string) $request->user()->getAuthIdentifier(),
            search: $search === '' ? null : $search,
            page: max(1, $request->integer('page', 1)),
        );

        return Inertia::render('Edificio/index', [
            'edificios' => [
                'data' => array_map(
                    static fn ($edificio): array => $edificio->toArray(),
                    $result['items'],
                ),
                'meta' => [
                    'total' => $result['total'],
                    'currentPage' => $result['currentPage'],
                    'lastPage' => $result['lastPage'],
                    'perPage' => $result['perPage'],
                ],
            ],
            'filters' => ['buscar' => $search],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', EdificioEloquentModel::class);

        return Inertia::render('Edificio/create');
    }

    public function store(StoreEdificioRequest $request): RedirectResponse
    {
        $this->createEdificio->execute(
            $request->validated(),
            (string) $request->user()->getAuthIdentifier(),
        );

        return redirect()
            ->route('edificios.index')
            ->with('success', 'Edificio creado exitosamente.');
    }

    public function show(EdificioEloquentModel $edificio): Response
    {
        Gate::authorize('view', $edificio);

        return Inertia::render('Edificio/show', [
            'edificio' => $this->getEdificio->execute($edificio->id)->toArray(),
        ]);
    }

    public function edit(EdificioEloquentModel $edificio): Response
    {
        Gate::authorize('update', $edificio);

        return Inertia::render('Edificio/edit', [
            'edificio' => $this->getEdificio->execute($edificio->id)->toArray(),
        ]);
    }

    public function update(
        UpdateEdificioRequest $request,
        EdificioEloquentModel $edificio,
    ): RedirectResponse {
        $this->updateEdificio->execute($edificio->id, $request->validated());

        return redirect()
            ->route('edificios.show', $edificio->id)
            ->with('success', 'Edificio actualizado exitosamente.');
    }

    public function changeStatus(
        ChangeEdificioStatusRequest $request,
        EdificioEloquentModel $edificio,
    ): RedirectResponse {
        $estado = EstadoEdificio::from($request->validated('estado'));
        $this->changeEdificioStatus->execute($edificio->id, $estado);

        return redirect()
            ->back()
            ->with(
                'success',
                $estado === EstadoEdificio::ACTIVO
                    ? 'Edificio activado exitosamente.'
                    : 'Edificio inactivado exitosamente.',
            );
    }
}
