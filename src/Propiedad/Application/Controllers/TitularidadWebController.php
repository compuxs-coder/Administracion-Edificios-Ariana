<?php

namespace Src\Propiedad\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Application\Actions\AssignPropietarioAction;
use Src\Propiedad\Application\Actions\FinalizeTitularidadAction;
use Src\Propiedad\Application\Actions\TransferPropiedadAction;
use Src\Propiedad\Infrastructure\Requests\AssignPropietarioRequest;
use Src\Propiedad\Infrastructure\Requests\FinalizeTitularidadRequest;
use Src\Propiedad\Infrastructure\Requests\TransferPropiedadRequest;

final class TitularidadWebController extends Controller
{
    public function __construct(
        private readonly AssignPropietarioAction $assignPropietario,
        private readonly FinalizeTitularidadAction $finalizeTitularidad,
        private readonly TransferPropiedadAction $transferPropiedad,
    ) {}

    public function assign(
        AssignPropietarioRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): RedirectResponse {
        $this->assignPropietario->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $departamento,
            $request->validated(),
        );

        return back()->with('success', 'Propietario asignado exitosamente.');
    }

    public function finalize(
        FinalizeTitularidadRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
        string $titularidad,
    ): RedirectResponse {
        $this->finalizeTitularidad->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $departamento,
            $titularidad,
            $request->validated(),
        );

        return back()->with('success', 'Titularidad finalizada y conservada en el historial.');
    }

    public function transfer(
        TransferPropiedadRequest $request,
        EdificioEloquentModel $edificio,
        string $departamento,
    ): RedirectResponse {
        $this->transferPropiedad->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $departamento,
            $request->validated(),
        );

        return back()->with('success', 'Transferencia de propiedad registrada exitosamente.');
    }
}
