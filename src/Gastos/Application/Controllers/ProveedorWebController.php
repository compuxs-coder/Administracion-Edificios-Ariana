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
use Src\Gastos\Application\Actions\ChangeProveedorStatusAction;
use Src\Gastos\Application\Actions\CreateProveedorAction;
use Src\Gastos\Application\Actions\GetProveedorAction;
use Src\Gastos\Application\Actions\GetGastosOptionsAction;
use Src\Gastos\Application\Actions\ListProveedoresAction;
use Src\Gastos\Application\Actions\UpdateProveedorAction;
use Src\Gastos\Domain\Enums\EstadoProveedor;
use Src\Gastos\Infrastructure\Requests\ChangeProveedorStatusRequest;
use Src\Gastos\Infrastructure\Requests\StoreProveedorRequest;
use Src\Gastos\Infrastructure\Requests\UpdateProveedorRequest;

final class ProveedorWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListProveedoresAction $list,
        private readonly GetProveedorAction $get,
        private readonly GetGastosOptionsAction $getOptions,
        private readonly CreateProveedorAction $create,
        private readonly UpdateProveedorAction $update,
        private readonly ChangeProveedorStatusAction $changeStatus,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoProveedor::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;

        $result = $this->list->execute($userId, $filters);
        $options = $this->getOptions->execute($userId);

        return Inertia::render('Proveedor/index', [
            'proveedores' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'],
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'perPage' => $result['perPage'],
            ]],
            'filters' => $filters,
            'edificios' => $options['edificios'],
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_GESTIONAR), 403);
        $filters = $request->validate(['edificio_id' => ['nullable', 'uuid']]);
        $options = $this->getOptions->execute($userId, PermisoEdificio::GASTOS_GESTIONAR);

        return Inertia::render('Proveedor/create', [
            'edificios' => $options['edificios'],
            'edificioSeleccionado' => $filters['edificio_id'] ?? null,
        ]);
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $proveedor): Response
    {
        return Inertia::render('Proveedor/show', ['proveedor' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $proveedor,
        )]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $proveedor): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::GASTOS_GESTIONAR), 403);

        return Inertia::render('Proveedor/edit', ['proveedor' => $this->get->execute(
            $userId,
            $edificio->id,
            $proveedor,
        )]);
    }

    public function store(StoreProveedorRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()->route('proveedores.show', [$edificio->id, $created['id']])
            ->with('success', 'Proveedor creado exitosamente.');
    }

    public function update(UpdateProveedorRequest $request, EdificioEloquentModel $edificio, string $proveedor): RedirectResponse
    {
        $this->update->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $proveedor,
            $request->validated(),
        );

        return redirect()->route('proveedores.show', [$edificio->id, $proveedor])
            ->with('success', 'Proveedor actualizado exitosamente.');
    }

    public function changeStatus(ChangeProveedorStatusRequest $request, EdificioEloquentModel $edificio, string $proveedor): RedirectResponse
    {
        $this->changeStatus->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $proveedor,
            EstadoProveedor::from($request->validated('estado')),
        );

        return back()->with('success', 'Estado del proveedor actualizado exitosamente.');
    }
}
