<?php

namespace Src\Edificio\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Actions\AcceptInvitacionEdificioAction;
use Src\Edificio\Application\Actions\GetAccesoEdificioAction;
use Src\Edificio\Application\Actions\GetInvitacionEdificioAction;
use Src\Edificio\Application\Actions\InviteEdificioMemberAction;
use Src\Edificio\Application\Actions\RevokeEdificioMemberAction;
use Src\Edificio\Application\Actions\RevokeInvitacionEdificioAction;
use Src\Edificio\Application\Actions\UpdateMemberRolesAction;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Requests\InviteEdificioMemberRequest;
use Src\Edificio\Infrastructure\Requests\UpdateMemberRolesRequest;

final class AccesoEdificioWebController extends Controller
{
    public function __construct(
        private readonly GetAccesoEdificioAction $getAccess,
        private readonly InviteEdificioMemberAction $inviteMember,
        private readonly GetInvitacionEdificioAction $getInvitation,
        private readonly AcceptInvitacionEdificioAction $acceptInvitation,
        private readonly RevokeInvitacionEdificioAction $revokeInvitation,
        private readonly UpdateMemberRolesAction $updateRoles,
        private readonly RevokeEdificioMemberAction $revokeMember,
    ) {}

    public function index(EdificioEloquentModel $edificio): Response
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::MIEMBROS_VER]);

        return Inertia::render('Acceso/index', [
            'edificio' => ['id' => $edificio->id, 'nombre' => $edificio->nombre],
            ...$this->getAccess->execute($edificio->id),
        ]);
    }

    public function invite(InviteEdificioMemberRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $this->inviteMember->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $request->validated('email'),
            $request->validated('rol'),
        );

        return back()->with('success', 'Invitación enviada exitosamente.');
    }

    public function updateRoles(
        UpdateMemberRolesRequest $request,
        EdificioEloquentModel $edificio,
        string $usuario,
    ): RedirectResponse {
        $this->updateRoles->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $usuario,
            $request->validated('roles'),
        );

        return back()->with('success', 'Roles actualizados exitosamente.');
    }

    public function revokeMember(Request $request, EdificioEloquentModel $edificio, string $usuario): RedirectResponse
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::MIEMBROS_GESTIONAR]);
        $this->revokeMember->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $usuario);

        return back()->with('success', 'Acceso al edificio revocado exitosamente.');
    }

    public function revokeInvitation(Request $request, EdificioEloquentModel $edificio, string $invitacion): RedirectResponse
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::MIEMBROS_GESTIONAR]);
        $this->revokeInvitation->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $invitacion);

        return back()->with('success', 'Invitación revocada exitosamente.');
    }

    public function showInvitation(Request $request, string $token): Response
    {
        return Inertia::render('Acceso/accept', [
            'invitacion' => $this->getInvitation->execute($token, (string) $request->user()->getAuthIdentifier()),
            'token' => $token,
        ]);
    }

    public function acceptInvitation(Request $request, string $token): RedirectResponse
    {
        $result = $this->acceptInvitation->execute($token, (string) $request->user()->getAuthIdentifier());

        return redirect()->route('edificios.show', $result['edificioId'])
            ->with('success', 'Invitación aceptada. Ya tienes acceso al edificio.');
    }
}
