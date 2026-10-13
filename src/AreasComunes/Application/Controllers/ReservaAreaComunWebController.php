<?php

namespace Src\AreasComunes\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\AreasComunes\Application\Actions\CreateReservaAreaComunAction;
use Src\AreasComunes\Application\Actions\GetAreasComunesOptionsAction;
use Src\AreasComunes\Application\Actions\GetReservaAreaComunAction;
use Src\AreasComunes\Application\Actions\ListReservasAreasComunesAction;
use Src\AreasComunes\Application\Actions\TransitionReservaAreaComunAction;
use Src\AreasComunes\Domain\Enums\EstadoReservaEspacioComun;
use Src\AreasComunes\Infrastructure\Requests\SaveReservaEspacioComunRequest;
use Src\AreasComunes\Infrastructure\Requests\TransitionReservaEspacioComunRequest;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class ReservaAreaComunWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListReservasAreasComunesAction $list,
        private readonly GetReservaAreaComunAction $get,
        private readonly GetAreasComunesOptionsAction $options,
        private readonly CreateReservaAreaComunAction $create,
        private readonly TransitionReservaAreaComunAction $transition,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER), 403);
        $filters = $request->validate([
            'edificio_id' => ['nullable', 'uuid'],
            'espacio_comun_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoReservaEspacioComun::class)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('ReservaAreaComun/index', [
            'reservas' => ['data' => $result['items'], 'meta' => $this->meta($result)],
            'filters' => $filters,
            ...$this->options->reservations($userId, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR), 403);
        $selection = $request->validate(['edificio_id' => ['nullable', 'uuid'], 'espacio_comun_id' => ['nullable', 'uuid']]);

        return Inertia::render('ReservaAreaComun/create', [
            ...$this->options->reservations($userId, PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR),
            'edificioSeleccionado' => $selection['edificio_id'] ?? null,
            'espacioSeleccionado' => $selection['espacio_comun_id'] ?? null,
        ]);
    }

    public function store(SaveReservaEspacioComunRequest $request, EdificioEloquentModel $edificio, string $espacio): RedirectResponse
    {
        $created = $this->create->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $espacio, $request->validated());

        return redirect()->route('reservas-areas-comunes.show', [$edificio->id, $created['id']])
            ->with('success', $created['estado'] === 'confirmada' ? 'Reserva confirmada.' : 'Solicitud de reserva registrada y pendiente de aprobación.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $reserva): Response
    {
        return Inertia::render('ReservaAreaComun/show', [
            'reserva' => $this->get->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $reserva),
        ]);
    }

    public function approve(TransitionReservaEspacioComunRequest $request, EdificioEloquentModel $edificio, string $reserva): RedirectResponse
    {
        $this->transition->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $reserva, 'confirmada', null);

        return back()->with('success', 'Reserva aprobada y horario bloqueado.');
    }

    public function reject(TransitionReservaEspacioComunRequest $request, EdificioEloquentModel $edificio, string $reserva): RedirectResponse
    {
        $this->transition->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $reserva, 'rechazada', $request->validated('motivo'));

        return back()->with('success', 'Solicitud de reserva rechazada.');
    }

    public function cancel(TransitionReservaEspacioComunRequest $request, EdificioEloquentModel $edificio, string $reserva): RedirectResponse
    {
        $this->transition->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $reserva, 'cancelada', $request->validated('motivo'));

        return back()->with('success', 'Reserva cancelada.');
    }

    private function meta(array $result): array
    {
        return ['total' => $result['total'], 'currentPage' => $result['currentPage'], 'lastPage' => $result['lastPage'], 'perPage' => $result['perPage']];
    }
}
