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
use Src\Finanzas\Application\Actions\ApplyPagoCreditAction;
use Src\Finanzas\Application\Actions\CancelPagoAction;
use Src\Finanzas\Application\Actions\CreatePagoAction;
use Src\Finanzas\Application\Actions\GetPagoAction;
use Src\Finanzas\Application\Actions\GetPagoOptionsAction;
use Src\Finanzas\Application\Actions\ListCarteraAction;
use Src\Finanzas\Application\Actions\ListPagosAction;
use Src\Finanzas\Application\Actions\PreviewPagoAction;
use Src\Finanzas\Domain\Enums\EstadoPago;
use Src\Finanzas\Domain\Enums\FormaPago;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Finanzas\Infrastructure\Requests\ApplyPagoCreditRequest;
use Src\Finanzas\Infrastructure\Requests\CancelPagoRequest;
use Src\Finanzas\Infrastructure\Requests\SavePagoRequest;

final class PagoWebController extends Controller
{
    public function __construct(
        private readonly ListPagosAction $listPagos,
        private readonly GetPagoAction $getPago,
        private readonly GetPagoOptionsAction $getOptions,
        private readonly PreviewPagoAction $previewPago,
        private readonly CreatePagoAction $createPago,
        private readonly ApplyPagoCreditAction $applyCredit,
        private readonly CancelPagoAction $cancelPago,
        private readonly ListCarteraAction $listCartera,
        private readonly AccesoEdificioService $access,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->allowsAny((string) $request->user()->getAuthIdentifier(), PermisoEdificio::FINANZAS_VER), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'departamento_id' => ['nullable', 'uuid'],
            'propietario_id' => ['nullable', 'uuid'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'forma_pago' => ['nullable', Rule::enum(FormaPago::class)],
            'estado' => ['nullable', Rule::enum(EstadoPago::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $userId = (string) $request->user()->getAuthIdentifier();
        $result = $this->listPagos->execute($userId, $filters);

        return Inertia::render('Pago/index', [
            'pagos' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'], 'currentPage' => $result['currentPage'], 'lastPage' => $result['lastPage'], 'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            ...$this->getOptions->execute($userId),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->access->allowsAny(
            (string) $request->user()->getAuthIdentifier(),
            PermisoEdificio::PAGOS_REGISTRAR,
        ), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'departamento_id' => ['nullable', 'uuid'],
            'fecha_pago' => ['nullable', 'date_format:Y-m-d'],
            'valor_recibido' => ['nullable', 'decimal:0,4', 'gt:0'],
        ]);
        $userId = (string) $request->user()->getAuthIdentifier();
        $preview = null;
        if (($filters['edificio_id'] ?? null) !== null && ($filters['departamento_id'] ?? null) !== null && ($filters['fecha_pago'] ?? null) !== null && ($filters['valor_recibido'] ?? null) !== null) {
            $preview = $this->previewPago->execute($userId, $filters['edificio_id'], $filters['departamento_id'], $filters['valor_recibido'], $filters['fecha_pago']);
        }

        return Inertia::render('Pago/create', [
            'filters' => $filters,
            'preview' => $preview,
            ...$this->getOptions->execute($userId, PermisoEdificio::PAGOS_REGISTRAR),
        ]);
    }

    public function store(SavePagoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $pago = $this->createPago->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $request->validated());

        return redirect()->route('pagos.show', [$edificio->id, $pago['id']])->with('success', 'Pago registrado, aplicado y recibo emitido exitosamente.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, PagoEloquentModel $pago): Response
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::FINANZAS_VER]);

        return Inertia::render('Pago/show', ['pago' => $this->getPago->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $pago->id)]);
    }

    public function applyCredit(ApplyPagoCreditRequest $request, EdificioEloquentModel $edificio, PagoEloquentModel $pago): RedirectResponse
    {
        $result = $this->applyCredit->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $pago->id);

        return back()->with('success', "Saldo a favor aplicado: {$result['valorAplicado']}.");
    }

    public function cancel(CancelPagoRequest $request, EdificioEloquentModel $edificio, PagoEloquentModel $pago): RedirectResponse
    {
        $this->cancelPago->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $pago->id, $request->validated('motivo'));

        return back()->with('success', 'Pago anulado y cargos restaurados exitosamente.');
    }

    public function cartera(Request $request): Response
    {
        Gate::authorize('viewAny', EdificioEloquentModel::class);
        $filters = $request->validate(['edificio_id' => ['nullable', 'uuid'], 'departamento_id' => ['nullable', 'uuid']]);
        $userId = (string) $request->user()->getAuthIdentifier();

        return Inertia::render('Cartera/index', ['cartera' => $this->listCartera->execute($userId, $filters), 'filters' => $filters, ...$this->getOptions->execute($userId)]);
    }
}
