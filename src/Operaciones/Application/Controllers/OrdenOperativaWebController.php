<?php

namespace Src\Operaciones\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Operaciones\Application\Actions\AssignOrdenOperativaAction;
use Src\Operaciones\Application\Actions\CancelOrdenOperativaAction;
use Src\Operaciones\Application\Actions\CreateOrdenOperativaAction;
use Src\Operaciones\Application\Actions\GetEvidenciaOrdenOperativaDownloadAction;
use Src\Operaciones\Application\Actions\GetOperacionesOptionsAction;
use Src\Operaciones\Application\Actions\GetOrdenOperativaAction;
use Src\Operaciones\Application\Actions\ListOrdenesOperativasAction;
use Src\Operaciones\Application\Actions\RecordActuacionOrdenOperativaAction;
use Src\Operaciones\Application\Actions\ReopenOrdenOperativaAction;
use Src\Operaciones\Application\Actions\StoreEvidenciaOrdenOperativaAction;
use Src\Operaciones\Application\Actions\TransitionOrdenOperativaAction;
use Src\Operaciones\Application\Actions\UpdateOrdenOperativaAction;
use Src\Operaciones\Domain\Enums\EstadoOrdenOperativa;
use Src\Operaciones\Domain\Enums\PrioridadOrdenOperativa;
use Src\Operaciones\Domain\Enums\TipoOrdenOperativa;
use Src\Operaciones\Infrastructure\Requests\AssignOrdenOperativaRequest;
use Src\Operaciones\Infrastructure\Requests\CancelOrdenOperativaRequest;
use Src\Operaciones\Infrastructure\Requests\ReopenOrdenOperativaRequest;
use Src\Operaciones\Infrastructure\Requests\SaveOrdenOperativaRequest;
use Src\Operaciones\Infrastructure\Requests\StoreActuacionOrdenOperativaRequest;
use Src\Operaciones\Infrastructure\Requests\StoreEvidenciaOrdenOperativaRequest;
use Src\Operaciones\Infrastructure\Requests\TransitionOrdenOperativaRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrdenOperativaWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListOrdenesOperativasAction $list,
        private readonly GetOrdenOperativaAction $get,
        private readonly GetOperacionesOptionsAction $getOptions,
        private readonly CreateOrdenOperativaAction $create,
        private readonly UpdateOrdenOperativaAction $update,
        private readonly TransitionOrdenOperativaAction $transition,
        private readonly AssignOrdenOperativaAction $assign,
        private readonly CancelOrdenOperativaAction $cancel,
        private readonly ReopenOrdenOperativaAction $reopen,
        private readonly RecordActuacionOrdenOperativaAction $recordAction,
        private readonly StoreEvidenciaOrdenOperativaAction $storeEvidence,
        private readonly GetEvidenciaOrdenOperativaDownloadAction $downloadEvidence,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::OPERACIONES_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'tipo' => ['nullable', Rule::enum(TipoOrdenOperativa::class)],
            'estado' => ['nullable', Rule::enum(EstadoOrdenOperativa::class)],
            'prioridad' => ['nullable', Rule::enum(PrioridadOrdenOperativa::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('Operacion/index', [
            'ordenes' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'],
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            ...$this->getOptions->forIndex($userId),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::OPERACIONES_GESTIONAR), 403);
        $options = $this->getOptions->execute($userId, PermisoEdificio::OPERACIONES_GESTIONAR);

        return Inertia::render('Operacion/create', [
            ...$options,
            'edificioSeleccionado' => $request->validate(['edificio_id' => ['nullable', 'uuid']])['edificio_id'] ?? null,
        ]);
    }

    public function store(SaveOrdenOperativaRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $request->validated());

        return redirect()->route('operaciones.show', [$edificio->id, $created['id']])
            ->with('success', 'Orden operativa '.$created['numero'].' creada exitosamente.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $orden): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $assignmentOptions = $this->access->allows($userId, $edificio->id, PermisoEdificio::OPERACIONES_ASIGNAR)
            ? $this->getOptions->forAssignment($userId, $edificio->id)
            : [];

        return Inertia::render('Operacion/show', [
            'orden' => $this->get->execute($userId, $edificio->id, $orden),
            'miembros' => $assignmentOptions['miembros'] ?? [],
            'proveedores' => $assignmentOptions['proveedores'] ?? [],
        ]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $orden): Response|RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::OPERACIONES_GESTIONAR), 403);
        $order = $this->get->execute($userId, $edificio->id, $orden, false);
        if (! $order['puedeEditar']) {
            return redirect()->route('operaciones.show', [$edificio->id, $orden])
                ->with('error', 'La orden ya no admite cambios en sus datos operativos.');
        }
        $options = $this->getOptions->execute($userId, PermisoEdificio::OPERACIONES_GESTIONAR, $edificio->id);

        return Inertia::render('Operacion/edit', [
            'orden' => $order,
            ...$options,
        ]);
    }

    public function update(SaveOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->update->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $orden, $request->validated());

        return redirect()->route('operaciones.show', [$edificio->id, $orden])->with('success', 'Orden operativa actualizada.');
    }

    public function transition(TransitionOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->transition->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $orden, $request->validated('estado'));

        return back()->with('success', 'Estado de la orden actualizado.');
    }

    public function assign(AssignOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->assign->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $orden,
            $request->validated('tipo_responsable'),
            $request->validated('responsable_id'),
        );

        return back()->with('success', 'Responsable asignado a la orden.');
    }

    public function cancel(CancelOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->cancel->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $orden, $request->validated('motivo'));

        return back()->with('success', 'Orden operativa cancelada.');
    }

    public function reopen(ReopenOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->reopen->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $orden, $request->validated('motivo'));

        return back()->with('success', 'Orden operativa reabierta.');
    }

    public function storeAction(StoreActuacionOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->recordAction->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $orden, $request->validated('descripcion'));

        return back()->with('success', 'Actuación registrada.');
    }

    public function storeEvidence(StoreEvidenciaOrdenOperativaRequest $request, EdificioEloquentModel $edificio, string $orden): RedirectResponse
    {
        $this->storeEvidence->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $orden,
            $request->file('archivo'),
            $request->validated('descripcion'),
        );

        return back()->with('success', 'Evidencia adjuntada.');
    }

    public function downloadEvidence(Request $request, EdificioEloquentModel $edificio, string $orden, string $evidencia): StreamedResponse
    {
        $document = $this->downloadEvidence->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $orden,
            $evidencia,
        );

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document['contents'];
            },
            $document['nombre'],
            [
                'Content-Type' => $document['mimeType'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
