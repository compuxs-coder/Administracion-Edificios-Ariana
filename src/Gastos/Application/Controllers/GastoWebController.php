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
use Src\Gastos\Application\Actions\CancelGastoAction;
use Src\Gastos\Application\Actions\CreateGastoAction;
use Src\Gastos\Application\Actions\GetGastoAction;
use Src\Gastos\Application\Actions\GetGastosOptionsAction;
use Src\Gastos\Application\Actions\ListGastosAction;
use Src\Gastos\Application\Actions\RegisterGastoAction;
use Src\Gastos\Application\Actions\UpdateGastoAction;
use Src\Gastos\Domain\Enums\EstadoGasto;
use Src\Gastos\Domain\Enums\TipoPagoGasto;
use Src\Gastos\Infrastructure\Requests\CancelGastosResourceRequest;
use Src\Gastos\Infrastructure\Requests\RegisterGastosResourceRequest;
use Src\Gastos\Infrastructure\Requests\SaveGastoRequest;

final class GastoWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListGastosAction $list,
        private readonly GetGastoAction $get,
        private readonly GetGastosOptionsAction $getOptions,
        private readonly CreateGastoAction $create,
        private readonly UpdateGastoAction $update,
        private readonly RegisterGastoAction $register,
        private readonly CancelGastoAction $cancel,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
            'contrato_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoGasto::class)],
            'tipo_pago' => ['nullable', Rule::enum(TipoPagoGasto::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;

        $result = $this->list->execute($userId, $filters);

        return Inertia::render('Gasto/index', [
            'gastos' => ['data' => $result['items'], 'meta' => [
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
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_GESTIONAR), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
            'contrato_id' => ['nullable', 'uuid'],
        ]);

        return Inertia::render('Gasto/create', [
            ...$this->getOptions->execute($userId, PermisoEdificio::GASTOS_GESTIONAR),
            'edificioSeleccionado' => $filters['edificio_id'] ?? null,
            'proveedorSeleccionado' => $filters['proveedor_id'] ?? null,
            'contratoSeleccionado' => $filters['contrato_id'] ?? null,
        ]);
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $gasto): Response
    {
        return Inertia::render('Gasto/show', ['gasto' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $gasto,
        )]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $gasto): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::GASTOS_GESTIONAR), 403);

        return Inertia::render('Gasto/edit', [
            'gasto' => $this->get->execute($userId, $edificio->id, $gasto),
            ...$this->getOptions->execute($userId, PermisoEdificio::GASTOS_GESTIONAR),
        ]);
    }

    public function store(SaveGastoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()->route('gastos.show', [$edificio->id, $created['id']])
            ->with('success', 'Gasto creado como borrador.');
    }

    public function update(SaveGastoRequest $request, EdificioEloquentModel $edificio, string $gasto): RedirectResponse
    {
        $this->update->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $gasto,
            $request->validated(),
        );

        return redirect()->route('gastos.show', [$edificio->id, $gasto])
            ->with('success', 'Gasto actualizado exitosamente.');
    }

    public function register(RegisterGastosResourceRequest $request, EdificioEloquentModel $edificio, string $gasto): RedirectResponse
    {
        $this->register->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $gasto,
        );

        return back()->with('success', 'Gasto registrado exitosamente.');
    }

    public function cancel(CancelGastosResourceRequest $request, EdificioEloquentModel $edificio, string $gasto): RedirectResponse
    {
        $this->cancel->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $gasto,
            $request->validated('motivo'),
        );

        return back()->with('success', 'Gasto anulado exitosamente.');
    }
}
