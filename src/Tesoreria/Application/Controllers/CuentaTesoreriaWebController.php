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
use Src\Tesoreria\Application\Actions\ChangeCuentaTesoreriaStatusAction;
use Src\Tesoreria\Application\Actions\CreateCuentaTesoreriaAction;
use Src\Tesoreria\Application\Actions\GetCuentaTesoreriaAction;
use Src\Tesoreria\Application\Actions\GetCuentaTesoreriaOptionsAction;
use Src\Tesoreria\Application\Actions\ListCuentasTesoreriaAction;
use Src\Tesoreria\Application\Actions\UpdateCuentaTesoreriaAction;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;
use Src\Tesoreria\Domain\Enums\TipoCuentaTesoreria;
use Src\Tesoreria\Infrastructure\Requests\ChangeCuentaTesoreriaStatusRequest;
use Src\Tesoreria\Infrastructure\Requests\SaveCuentaTesoreriaRequest;

final class CuentaTesoreriaWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListCuentasTesoreriaAction $list,
        private readonly GetCuentaTesoreriaAction $get,
        private readonly GetCuentaTesoreriaOptionsAction $getOptions,
        private readonly CreateCuentaTesoreriaAction $create,
        private readonly UpdateCuentaTesoreriaAction $update,
        private readonly ChangeCuentaTesoreriaStatusAction $changeStatus,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::TESORERIA_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'tipo' => ['nullable', Rule::enum(TipoCuentaTesoreria::class)],
            'estado' => ['nullable', Rule::enum(EstadoCuentaTesoreria::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('CuentaTesoreria/index', [
            'cuentas' => ['data' => $result['items'], 'meta' => [
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
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR), 403);
        $filters = $request->validate(['edificio_id' => ['nullable', 'uuid']]);

        return Inertia::render('CuentaTesoreria/create', [
            'edificioSeleccionado' => $filters['edificio_id'] ?? null,
            ...$this->getOptions->execute($userId, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR),
        ]);
    }

    public function store(SaveCuentaTesoreriaRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated(),
        );

        return redirect()->route('cuentas-tesoreria.show', [$edificio->id, $created['id']])
            ->with('success', 'Cuenta de tesorería creada exitosamente.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $cuenta): Response
    {
        return Inertia::render('CuentaTesoreria/show', ['cuenta' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
        )]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $cuenta): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows(
            $userId,
            $edificio->id,
            PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR,
        ), 403);

        return Inertia::render('CuentaTesoreria/edit', ['cuenta' => $this->get->executeForManagement(
            $userId,
            $edificio->id,
            $cuenta,
        )]);
    }

    public function update(
        SaveCuentaTesoreriaRequest $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
    ): RedirectResponse {
        $this->update->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            $request->validated(),
        );

        return redirect()->route('cuentas-tesoreria.show', [$edificio->id, $cuenta])
            ->with('success', 'Cuenta de tesorería actualizada exitosamente.');
    }

    public function changeStatus(
        ChangeCuentaTesoreriaStatusRequest $request,
        EdificioEloquentModel $edificio,
        string $cuenta,
    ): RedirectResponse {
        $this->changeStatus->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
            EstadoCuentaTesoreria::from($request->validated('estado')),
        );

        return back()->with('success', 'Estado de la cuenta actualizado exitosamente.');
    }
}
