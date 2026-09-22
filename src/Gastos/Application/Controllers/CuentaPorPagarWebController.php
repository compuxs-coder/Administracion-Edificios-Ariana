<?php

namespace Src\Gastos\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Application\Actions\GetCuentaPorPagarAction;
use Src\Gastos\Application\Actions\GetGastosOptionsAction;
use Src\Gastos\Application\Actions\ListCuentasPorPagarAction;

final class CuentaPorPagarWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListCuentasPorPagarAction $list,
        private readonly GetCuentaPorPagarAction $get,
        private readonly GetGastosOptionsAction $getOptions,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GASTOS_VER), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'proveedor_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::in(['pendiente', 'parcial', 'pagada', 'anulada'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->list->execute($userId, $filters);

        return Inertia::render('CuentaPorPagar/index', [
            'cuentasPorPagar' => ['data' => $result['items'], 'meta' => [
                'total' => $result['total'],
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'perPage' => $result['perPage'],
            ]],
            'resumen' => $result['summary'],
            'filters' => $filters,
            ...$this->getOptions->execute($userId),
        ]);
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $cuenta): Response
    {
        return Inertia::render('CuentaPorPagar/show', ['cuentaPorPagar' => $this->get->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $cuenta,
        )]);
    }
}
