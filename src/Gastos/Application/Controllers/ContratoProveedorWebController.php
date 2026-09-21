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
use Src\Gastos\Application\Actions\CancelContratoProveedorAction;
use Src\Gastos\Application\Actions\CreateContratoProveedorAction;
use Src\Gastos\Application\Actions\GetContratoProveedorAction;
use Src\Gastos\Application\Actions\GetGastosOptionsAction;
use Src\Gastos\Application\Actions\ListContratosProveedorAction;
use Src\Gastos\Application\Actions\RegisterContratoProveedorAction;
use Src\Gastos\Application\Actions\UpdateContratoProveedorAction;
use Src\Gastos\Domain\Enums\EstadoContratoProveedor;
use Src\Gastos\Infrastructure\Requests\CancelGastosResourceRequest;
use Src\Gastos\Infrastructure\Requests\RegisterGastosResourceRequest;
use Src\Gastos\Infrastructure\Requests\SaveContratoProveedorRequest;

final class ContratoProveedorWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListContratosProveedorAction $list,
        private readonly GetContratoProveedorAction $get,
        private readonly GetGastosOptionsAction $getOptions,
        private readonly CreateContratoProveedorAction $create,
        private readonly UpdateContratoProveedorAction $update,
        private readonly RegisterContratoProveedorAction $register,
        private readonly CancelContratoProveedorAction $cancel,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoContratoProveedor::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;

        $result = $this->list->execute($userId, $filters);
        $options = $this->getOptions->execute($userId);

        return Inertia::render('ContratoProveedor/index', [
            'contratos' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'],
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            'edificios' => $options['edificios'],
            'proveedores' => $options['proveedores'],
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_GESTIONAR), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
        ]);

        return Inertia::render('ContratoProveedor/create', [
            ...$this->getOptions->execute($userId, PermisoEdificio::GASTOS_GESTIONAR),
            'edificioSeleccionado' => $filters['edificio_id'] ?? null,
            'proveedorSeleccionado' => $filters['proveedor_id'] ?? null,
        ]);
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $contrato): Response
    {
        return Inertia::render('ContratoProveedor/show', ['contrato' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $contrato,
        )]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $contrato): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::GASTOS_GESTIONAR), 403);

        return Inertia::render('ContratoProveedor/edit', [
            'contrato' => $this->get->execute($userId, $edificio->id, $contrato),
            ...$this->getOptions->execute($userId, PermisoEdificio::GASTOS_GESTIONAR),
        ]);
    }

    public function store(SaveContratoProveedorRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()->route('contratos-proveedor.show', [$edificio->id, $created['id']])
            ->with('success', 'Contrato creado como borrador.');
    }

    public function update(SaveContratoProveedorRequest $request, EdificioEloquentModel $edificio, string $contrato): RedirectResponse
    {
        $this->update->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $contrato,
            $request->validated(),
        );

        return redirect()->route('contratos-proveedor.show', [$edificio->id, $contrato])
            ->with('success', 'Contrato actualizado exitosamente.');
    }

    public function register(RegisterGastosResourceRequest $request, EdificioEloquentModel $edificio, string $contrato): RedirectResponse
    {
        $this->register->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $contrato,
        );

        return back()->with('success', 'Contrato registrado exitosamente.');
    }

    public function cancel(CancelGastosResourceRequest $request, EdificioEloquentModel $edificio, string $contrato): RedirectResponse
    {
        $this->cancel->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $contrato,
            $request->validated('motivo'),
        );

        return back()->with('success', 'Contrato anulado exitosamente.');
    }
}
