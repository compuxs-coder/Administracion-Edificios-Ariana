<?php

namespace Src\Propiedad\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Application\Actions\AssignResidenteAction;
use Src\Propiedad\Application\Actions\FinalizeOcupacionAction;
use Src\Propiedad\Infrastructure\Requests\AssignResidenteRequest;
use Src\Propiedad\Infrastructure\Requests\FinalizeOcupacionRequest;

final class OcupacionWebController extends Controller
{
    public function __construct(
        private readonly AssignResidenteAction $assignResidente,
        private readonly FinalizeOcupacionAction $finalizeOcupacion,
    ) {}

    public function assign(
        AssignResidenteRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): RedirectResponse {
        $this->assignResidente->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $departamento,
            $request->validated(),
        );

        return back()->with('success', 'Residente asignado exitosamente.');
    }

    public function finalize(
        FinalizeOcupacionRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
        string $ocupacion,
    ): RedirectResponse {
        $this->finalizeOcupacion->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $departamento,
            $ocupacion,
            $request->validated(),
        );

        return back()->with('success', 'Ocupación finalizada y conservada en el historial.');
    }
}
