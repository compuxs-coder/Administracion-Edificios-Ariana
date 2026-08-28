<?php

namespace Src\Finanzas\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Application\Actions\CancelCargoAction;
use Src\Finanzas\Application\Actions\CreateCargoManualAction;
use Src\Finanzas\Application\Actions\GenerateCargosAction;
use Src\Finanzas\Application\Actions\GetCargoAction;
use Src\Finanzas\Application\Actions\GetCargoOptionsAction;
use Src\Finanzas\Application\Actions\ListCargosAction;
use Src\Finanzas\Application\Actions\PreviewCargosAction;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Requests\CancelCargoRequest;
use Src\Finanzas\Infrastructure\Requests\GenerateCargosRequest;
use Src\Finanzas\Infrastructure\Requests\SaveCargoManualRequest;

final class CargoWebController extends Controller
{
    public function __construct(
        private readonly ListCargosAction $listCargos,
        private readonly GetCargoAction $getCargo,
        private readonly GetCargoOptionsAction $getOptions,
        private readonly PreviewCargosAction $previewCargos,
        private readonly GenerateCargosAction $generateCargos,
        private readonly CreateCargoManualAction $createManual,
        private readonly CancelCargoAction $cancelCargo,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'departamento_id' => ['nullable', 'uuid'],
            'concepto_cobro_id' => ['nullable', 'uuid'],
            'periodo' => ['nullable', 'date_format:Y-m'],
            'estado' => ['nullable', 'in:pendiente,parcial,pagado,anulado'],
            'origen' => ['nullable', 'in:automatico,manual,importado,ajuste'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $userId = (string) $request->user()->getAuthIdentifier();
        $result = $this->listCargos->execute($userId, $filters);

        return Inertia::render('Cargo/index', [
            'cargos' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'], 'currentPage' => $result['currentPage'], 'lastPage' => $result['lastPage'], 'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            ...$this->getOptions->execute($userId),
        ]);
    }

    public function generate(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);
        $data = $request->validate(['edificio_id' => ['nullable', 'uuid'], 'periodo' => ['nullable', 'date_format:Y-m'], 'concepto_cobro_id' => ['nullable', 'uuid']]);
        $userId = (string) $request->user()->getAuthIdentifier();
        $preview = null;
        if (($data['edificio_id'] ?? null) !== null && ($data['periodo'] ?? null) !== null) {
            $preview = $this->previewCargos->execute($userId, $data['edificio_id'], $data['periodo'], $data['concepto_cobro_id'] ?? null);
        }

        return Inertia::render('Cargo/generate', [
            'filters' => $data,
            'preview' => $preview,
            ...$this->getOptions->execute($userId),
        ]);
    }

    public function storeGeneration(GenerateCargosRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $result = $this->generateCargos->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated('periodo'),
            $request->validated('concepto_cobro_id'),
        );

        return redirect()->route('cargos.index', ['edificio_id' => $edificio->id, 'periodo' => $request->validated('periodo')])
            ->with('success', "Generación completada: {$result['cargosCreados']} cargo(s) creado(s), {$result['cargosOmitidos']} omitido(s).");
    }

    public function create(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);

        return Inertia::render('Cargo/create', $this->getOptions->execute((string) $request->user()->getAuthIdentifier()));
    }

    public function storeManual(SaveCargoManualRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $cargo = $this->createManual->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $request->validated());

        return redirect()->route('cargos.show', [$edificio->id, $cargo['id']])->with('success', 'Cargo manual creado exitosamente.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, CargoEloquentModel $cargo): Response
    {
        Gate::authorize('view', $edificio);

        return Inertia::render('Cargo/show', [
            'cargo' => $this->getCargo->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $cargo->id),
        ]);
    }

    public function cancel(CancelCargoRequest $request, EdificioEloquentModel $edificio, CargoEloquentModel $cargo): RedirectResponse
    {
        $this->cancelCargo->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $cargo->id, $request->validated('motivo'));

        return back()->with('success', 'Cargo anulado exitosamente.');
    }
}
