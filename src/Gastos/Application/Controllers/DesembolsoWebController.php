<?php

namespace Src\Gastos\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Application\Actions\CancelDesembolsoAction;
use Src\Gastos\Application\Actions\CreateDesembolsoAction;
use Src\Gastos\Application\Actions\GetDesembolsoAction;
use Src\Gastos\Application\Actions\GetDesembolsoOptionsAction;
use Src\Gastos\Application\Actions\ListDesembolsosAction;
use Src\Gastos\Application\Actions\PreviewDesembolsoAction;
use Src\Gastos\Domain\Enums\EstadoDesembolso;
use Src\Gastos\Domain\Enums\FormaDesembolso;
use Src\Gastos\Infrastructure\Requests\CancelDesembolsoRequest;
use Src\Gastos\Infrastructure\Requests\SaveDesembolsoRequest;

final class DesembolsoWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListDesembolsosAction $list,
        private readonly GetDesembolsoAction $get,
        private readonly GetDesembolsoOptionsAction $getOptions,
        private readonly PreviewDesembolsoAction $preview,
        private readonly CreateDesembolsoAction $create,
        private readonly CancelDesembolsoAction $cancel,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::DESEMBOLSOS_VER), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'forma_pago' => ['nullable', Rule::enum(FormaDesembolso::class)],
            'estado' => ['nullable', Rule::in([EstadoDesembolso::REGISTRADO->value, EstadoDesembolso::ANULADO->value])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('Desembolso/index', [
            'desembolsos' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'],
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            ...$this->getOptions->execute($userId),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::DESEMBOLSOS_REGISTRAR), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
            'fecha_desembolso' => ['nullable', 'date_format:Y-m-d'],
            'monto' => ['nullable', 'decimal:0,4', 'gt:0'],
        ]);
        $preview = null;
        if (($filters['edificio_id'] ?? null) !== null
            && ($filters['proveedor_id'] ?? null) !== null
            && ($filters['monto'] ?? null) !== null) {
            $preview = $this->preview->execute(
                $userId,
                $filters['edificio_id'],
                $filters['proveedor_id'],
                $filters['monto'],
            );
        }

        return Inertia::render('Desembolso/create', [
            'filters' => $filters,
            'preview' => $preview,
            ...$this->getOptions->execute($userId, PermisoEdificio::DESEMBOLSOS_REGISTRAR),
        ]);
    }

    public function store(SaveDesembolsoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $desembolso = $this->create->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()->route('desembolsos.show', [$edificio->id, $desembolso['id']])
            ->with('success', 'Desembolso registrado y aplicado exitosamente.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $desembolso): Response
    {
        return Inertia::render('Desembolso/show', ['desembolso' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $desembolso,
        )]);
    }

    public function cancel(CancelDesembolsoRequest $request, EdificioEloquentModel $edificio, string $desembolso): RedirectResponse
    {
        $this->cancel->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $desembolso,
            $request->validated('motivo'),
        );

        return back()->with('success', 'Desembolso anulado y saldos restaurados exitosamente.');
    }
}
