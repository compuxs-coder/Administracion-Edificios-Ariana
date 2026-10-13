<?php

namespace Src\AreasComunes\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\AreasComunes\Application\Actions\AddReglaEspacioComunAction;
use Src\AreasComunes\Application\Actions\ChangeEspacioComunStatusAction;
use Src\AreasComunes\Application\Actions\CreateEspacioComunAction;
use Src\AreasComunes\Application\Actions\GetAreasComunesOptionsAction;
use Src\AreasComunes\Application\Actions\GetEspacioComunAction;
use Src\AreasComunes\Application\Actions\ListEspaciosComunesAction;
use Src\AreasComunes\Application\Actions\UpdateEspacioComunAction;
use Src\AreasComunes\Domain\Enums\EstadoEspacioComun;
use Src\AreasComunes\Infrastructure\Requests\AddReglaEspacioComunRequest;
use Src\AreasComunes\Infrastructure\Requests\ChangeEspacioComunStatusRequest;
use Src\AreasComunes\Infrastructure\Requests\SaveEspacioComunRequest;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class EspacioComunWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListEspaciosComunesAction $list,
        private readonly GetEspacioComunAction $get,
        private readonly GetAreasComunesOptionsAction $options,
        private readonly CreateEspacioComunAction $create,
        private readonly UpdateEspacioComunAction $update,
        private readonly ChangeEspacioComunStatusAction $changeStatus,
        private readonly AddReglaEspacioComunAction $addRule,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::AREAS_COMUNES_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoEspacioComun::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('AreaComun/index', [
            'espacios' => ['data' => $result['items'], 'meta' => $this->meta($result)],
            'filters' => $filters,
            ...$this->options->spaces($userId, PermisoEdificio::AREAS_COMUNES_VER),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::AREAS_COMUNES_GESTIONAR), 403);

        return Inertia::render('AreaComun/create', [
            ...$this->options->spaces($userId, PermisoEdificio::AREAS_COMUNES_GESTIONAR),
            'edificioSeleccionado' => $request->validate(['edificio_id' => ['nullable', 'uuid']])['edificio_id'] ?? null,
        ]);
    }

    public function store(SaveEspacioComunRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $request->validated());

        return redirect()->route('areas-comunes.show', [$edificio->id, $created['id']])
            ->with('success', 'Espacio común '.$created['codigo'].' creado. Registre su primera regla de uso.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $espacio): Response
    {
        return Inertia::render('AreaComun/show', [
            'espacio' => $this->get->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $espacio),
        ]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $espacio): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::AREAS_COMUNES_GESTIONAR), 403);

        return Inertia::render('AreaComun/edit', [
            'espacio' => $this->get->execute($userId, $edificio->id, $espacio),
            ...$this->options->spaces($userId, PermisoEdificio::AREAS_COMUNES_GESTIONAR, $edificio->id),
        ]);
    }

    public function update(SaveEspacioComunRequest $request, EdificioEloquentModel $edificio, string $espacio): RedirectResponse
    {
        $this->update->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $espacio, $request->validated());

        return redirect()->route('areas-comunes.show', [$edificio->id, $espacio])->with('success', 'Espacio común actualizado.');
    }

    public function changeStatus(ChangeEspacioComunStatusRequest $request, EdificioEloquentModel $edificio, string $espacio): RedirectResponse
    {
        $this->changeStatus->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $espacio, $request->validated('estado'));

        return back()->with('success', $request->validated('estado') === 'activo' ? 'Espacio activado.' : 'Espacio inactivado.');
    }

    public function storeRule(AddReglaEspacioComunRequest $request, EdificioEloquentModel $edificio, string $espacio): RedirectResponse
    {
        $this->addRule->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $espacio, $request->validated());

        return back()->with('success', 'Nueva versión de reglas registrada.');
    }

    private function meta(array $result): array
    {
        return ['total' => $result['total'], 'currentPage' => $result['currentPage'], 'lastPage' => $result['lastPage'], 'perPage' => $result['perPage']];
    }
}
