<?php

namespace Src\Finanzas\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Application\Actions\CreateLecturaConsumoAction;
use Src\Finanzas\Application\Actions\GetLecturaConsumoOptionsAction;
use Src\Finanzas\Application\Actions\ListLecturasConsumoAction;
use Src\Finanzas\Infrastructure\Requests\StoreLecturaConsumoRequest;

final class LecturaConsumoWebController extends Controller
{
    public function __construct(
        private readonly ListLecturasConsumoAction $listLecturas,
        private readonly GetLecturaConsumoOptionsAction $getOptions,
        private readonly CreateLecturaConsumoAction $createLectura,
        private readonly AccesoEdificioService $access,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::FINANZAS_VER), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'departamento_id' => ['nullable', 'uuid'],
            'concepto_cobro_id' => ['nullable', 'uuid'],
            'periodo' => ['nullable', 'date_format:Y-m'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->listLecturas->execute($userId, $filters);

        return Inertia::render('LecturaConsumo/index', [
            'lecturas' => [
                'data' => $result['items'],
                'meta' => [
                    'total' => $result['total'],
                    'currentPage' => $result['currentPage'],
                    'lastPage' => $result['lastPage'],
                    'perPage' => $result['perPage'],
                ],
            ],
            'filters' => $filters,
            ...$this->getOptions->execute($userId),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::LECTURAS_REGISTRAR), 403);

        return Inertia::render('LecturaConsumo/create', [
            ...$this->getOptions->execute($userId, PermisoEdificio::LECTURAS_REGISTRAR),
            'edificioSeleccionado' => $request->query('edificio'),
        ]);
    }

    public function store(StoreLecturaConsumoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $this->createLectura->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()->route('lecturas.index', [
            'edificio_id' => $edificio->id,
            'departamento_id' => $request->validated('departamento_id'),
            'concepto_cobro_id' => $request->validated('concepto_cobro_id'),
        ])->with('success', 'Lectura de consumo registrada exitosamente.');
    }
}
