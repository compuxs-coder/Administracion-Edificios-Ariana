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
use Src\Operaciones\Application\Actions\ChangePlanMantenimientoPreventivoStatusAction;
use Src\Operaciones\Application\Actions\CreatePlanMantenimientoPreventivoAction;
use Src\Operaciones\Application\Actions\GetMantenimientoPreventivoOptionsAction;
use Src\Operaciones\Application\Actions\GetPlanMantenimientoPreventivoAction;
use Src\Operaciones\Application\Actions\ListPlanesMantenimientoPreventivoAction;
use Src\Operaciones\Application\Actions\OmitOccurrenceMantenimientoPreventivoAction;
use Src\Operaciones\Application\Actions\RetryOccurrenceMantenimientoPreventivoAction;
use Src\Operaciones\Application\Actions\UpdatePlanMantenimientoPreventivoAction;
use Src\Operaciones\Domain\Enums\EstadoPlanMantenimientoPreventivo;
use Src\Operaciones\Domain\Enums\UnidadRecurrenciaMantenimiento;
use Src\Operaciones\Infrastructure\Requests\ChangePlanMantenimientoPreventivoStatusRequest;
use Src\Operaciones\Infrastructure\Requests\OmitOccurrenceMantenimientoPreventivoRequest;
use Src\Operaciones\Infrastructure\Requests\RetryOccurrenceMantenimientoPreventivoRequest;
use Src\Operaciones\Infrastructure\Requests\SavePlanMantenimientoPreventivoRequest;

final class MantenimientoPreventivoWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListPlanesMantenimientoPreventivoAction $list,
        private readonly GetPlanMantenimientoPreventivoAction $get,
        private readonly GetMantenimientoPreventivoOptionsAction $getOptions,
        private readonly CreatePlanMantenimientoPreventivoAction $create,
        private readonly UpdatePlanMantenimientoPreventivoAction $update,
        private readonly ChangePlanMantenimientoPreventivoStatusAction $changeStatus,
        private readonly RetryOccurrenceMantenimientoPreventivoAction $retryOccurrence,
        private readonly OmitOccurrenceMantenimientoPreventivoAction $omitOccurrence,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoPlanMantenimientoPreventivo::class)],
            'unidad_recurrencia' => ['nullable', Rule::enum(UnidadRecurrenciaMantenimiento::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('MantenimientoPreventivo/index', [
            'planes' => ['data' => $result['items'], 'meta' => [
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
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR), 403);

        return Inertia::render('MantenimientoPreventivo/create', [
            ...$this->getOptions->execute($userId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR),
            'edificioSeleccionado' => $request->validate(['edificio_id' => ['nullable', 'uuid']])['edificio_id'] ?? null,
        ]);
    }

    public function store(SavePlanMantenimientoPreventivoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $request->validated());

        return redirect()->route('mantenimiento-preventivo.show', [$edificio->id, $created['id']])
            ->with('success', 'Plan preventivo '.$created['codigo'].' creado en estado inactivo.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $plan): Response
    {
        return Inertia::render('MantenimientoPreventivo/show', [
            'plan' => $this->get->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $plan),
        ]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $plan): Response|RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR), 403);
        $current = $this->get->execute($userId, $edificio->id, $plan);
        if ($current['estado'] !== EstadoPlanMantenimientoPreventivo::INACTIVO->value) {
            return redirect()->route('mantenimiento-preventivo.show', [$edificio->id, $plan])
                ->with('error', 'Pause el plan antes de modificar su configuración.');
        }

        return Inertia::render('MantenimientoPreventivo/edit', [
            'plan' => $current,
            ...$this->getOptions->execute($userId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR, $edificio->id),
        ]);
    }

    public function update(SavePlanMantenimientoPreventivoRequest $request, EdificioEloquentModel $edificio, string $plan): RedirectResponse
    {
        $this->update->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $plan, $request->validated());

        return redirect()->route('mantenimiento-preventivo.show', [$edificio->id, $plan])->with('success', 'Plan preventivo actualizado.');
    }

    public function changeStatus(ChangePlanMantenimientoPreventivoStatusRequest $request, EdificioEloquentModel $edificio, string $plan): RedirectResponse
    {
        $this->changeStatus->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $plan,
            $request->validated('estado'),
            $request->validated('proxima_fecha_programada'),
        );

        return back()->with('success', $request->validated('estado') === 'activo' ? 'Plan preventivo activado.' : 'Plan preventivo pausado.');
    }

    public function retry(RetryOccurrenceMantenimientoPreventivoRequest $request, EdificioEloquentModel $edificio, string $plan, string $ocurrencia): RedirectResponse
    {
        $this->retryOccurrence->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $plan, $ocurrencia);

        return back()->with('success', 'Reintento de la ocurrencia procesado.');
    }

    public function omit(OmitOccurrenceMantenimientoPreventivoRequest $request, EdificioEloquentModel $edificio, string $plan, string $ocurrencia): RedirectResponse
    {
        $this->omitOccurrence->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $plan,
            $ocurrencia,
            $request->validated('motivo'),
        );

        return back()->with('success', 'Ocurrencia omitida y programación avanzada.');
    }
}
