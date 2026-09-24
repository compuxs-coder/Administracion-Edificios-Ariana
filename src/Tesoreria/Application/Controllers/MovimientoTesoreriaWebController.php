<?php

namespace Src\Tesoreria\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Application\Actions\CancelMovimientoTesoreriaAction;
use Src\Tesoreria\Application\Actions\CreateConciliacionTesoreriaAction;
use Src\Tesoreria\Application\Actions\CreateMovimientoTesoreriaAction;
use Src\Tesoreria\Application\Actions\GetConciliacionTesoreriaOptionsAction;
use Src\Tesoreria\Application\Actions\GetMovimientoTesoreriaAction;
use Src\Tesoreria\Application\Actions\GetMovimientoTesoreriaOptionsAction;
use Src\Tesoreria\Application\Actions\ListMovimientosTesoreriaAction;
use Src\Tesoreria\Application\Actions\ReverseConciliacionTesoreriaAction;
use Src\Tesoreria\Domain\Enums\EstadoEfectivoMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\NaturalezaMovimientoTesoreria;
use Src\Tesoreria\Infrastructure\Requests\CancelMovimientoTesoreriaRequest;
use Src\Tesoreria\Infrastructure\Requests\ReverseConciliacionTesoreriaRequest;
use Src\Tesoreria\Infrastructure\Requests\StoreConciliacionTesoreriaRequest;
use Src\Tesoreria\Infrastructure\Requests\StoreMovimientoTesoreriaRequest;

final class MovimientoTesoreriaWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListMovimientosTesoreriaAction $list,
        private readonly GetMovimientoTesoreriaAction $get,
        private readonly GetMovimientoTesoreriaOptionsAction $getOptions,
        private readonly CreateMovimientoTesoreriaAction $create,
        private readonly CancelMovimientoTesoreriaAction $cancel,
        private readonly GetConciliacionTesoreriaOptionsAction $getReconciliationOptions,
        private readonly CreateConciliacionTesoreriaAction $reconcile,
        private readonly ReverseConciliacionTesoreriaAction $reverse,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::TESORERIA_VER), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'cuenta_id' => ['nullable', 'uuid'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'naturaleza' => ['nullable', Rule::enum(NaturalezaMovimientoTesoreria::class)],
            'estado' => ['nullable', Rule::enum(EstadoMovimientoTesoreria::class)],
            'estado_conciliacion' => ['nullable', Rule::enum(EstadoEfectivoMovimientoTesoreria::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('MovimientoTesoreria/index', [
            'movimientos' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'],
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            ...$this->getOptions->execute($userId, PermisoEdificio::TESORERIA_VER),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::MOVIMIENTOS_TESORERIA_REGISTRAR), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'cuenta_id' => ['nullable', 'uuid'],
        ]);

        return Inertia::render('MovimientoTesoreria/create', [
            'edificioSeleccionado' => $filters['edificio_id'] ?? null,
            'cuentaSeleccionada' => $filters['cuenta_id'] ?? null,
            ...$this->getOptions->execute(
                $userId,
                PermisoEdificio::MOVIMIENTOS_TESORERIA_REGISTRAR,
                true,
            ),
        ]);
    }

    public function store(
        StoreMovimientoTesoreriaRequest $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
    ): RedirectResponse {
        $created = $this->create->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            $request->validated(),
        );

        return redirect()->route('movimientos-tesoreria.show', [$edificio->id, $cuenta, $created['id']])
            ->with('success', 'Movimiento de tesorería registrado exitosamente.');
    }

    public function show(
        Request $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
        string $movimiento,
    ): Response {
        return Inertia::render('MovimientoTesoreria/show', ['movimiento' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            $movimiento,
        )]);
    }

    public function cancel(
        CancelMovimientoTesoreriaRequest $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
        string $movimiento,
    ): RedirectResponse {
        $this->cancel->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            $movimiento,
            $request->validated('motivo'),
        );

        return back()->with('success', 'Movimiento de tesorería anulado exitosamente.');
    }

    public function reconcile(
        Request $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
        string $movimiento,
    ): Response {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows(
            $userId,
            $edificio->id,
            PermisoEdificio::CONCILIACIONES_GESTIONAR,
        ), 403);

        return Inertia::render('MovimientoTesoreria/reconcile', $this->getReconciliationOptions->execute(
            $userId,
            $edificio->id,
            $cuenta,
            $movimiento,
        ));
    }

    public function storeReconciliation(
        StoreConciliacionTesoreriaRequest $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
        string $movimiento,
    ): RedirectResponse {
        $this->reconcile->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            $movimiento,
            $request->validated(),
        );

        return redirect()->route('movimientos-tesoreria.show', [$edificio->id, $cuenta, $movimiento])
            ->with('success', 'Movimiento conciliado exitosamente.');
    }

    public function reverseReconciliation(
        ReverseConciliacionTesoreriaRequest $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
        string $movimiento,
        string $conciliacion,
    ): RedirectResponse {
        $this->reverse->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            $movimiento,
            $conciliacion,
            $request->validated('motivo'),
        );

        return back()->with('success', 'Conciliación revertida exitosamente.');
    }
}
