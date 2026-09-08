<?php

namespace Src\Edificio\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Actions\ChangeEstructuraStatusAction;
use Src\Edificio\Application\Actions\GetEdificioAction;
use Src\Edificio\Application\Actions\GetEstructuraAction;
use Src\Edificio\Application\Actions\SaveAnexoAction;
use Src\Edificio\Application\Actions\SavePisoAction;
use Src\Edificio\Application\Actions\SaveTorreAction;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\TipoAnexo;
use Src\Edificio\Domain\Enums\TipoElementoEstructura;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Requests\ChangeEstructuraStatusRequest;
use Src\Edificio\Infrastructure\Requests\SaveAnexoRequest;
use Src\Edificio\Infrastructure\Requests\SavePisoRequest;
use Src\Edificio\Infrastructure\Requests\SaveTorreRequest;

final class EstructuraWebController extends Controller
{
    public function __construct(
        private readonly GetEdificioAction $getEdificio,
        private readonly GetEstructuraAction $getEstructura,
        private readonly SaveTorreAction $saveTorre,
        private readonly SavePisoAction $savePiso,
        private readonly SaveAnexoAction $saveAnexo,
        private readonly ChangeEstructuraStatusAction $changeStatus,
    ) {}

    public function index(EdificioEloquentModel $edificio): Response
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::ESTRUCTURA_VER]);

        return Inertia::render('Edificio/estructura', [
            'edificio' => $this->getEdificio->execute($edificio->id)->toArray(),
            'estructura' => $this->getEstructura->execute($edificio->id),
        ]);
    }

    public function storeTorre(SaveTorreRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $this->saveTorre->execute($edificio->id, null, $request->validated());

        return back()->with('success', 'Torre creada exitosamente.');
    }

    public function updateTorre(
        SaveTorreRequest $request,
        EdificioEloquentModel $edificio,
        string $torre,
    ): RedirectResponse {
        $this->saveTorre->execute($edificio->id, $torre, $request->validated());

        return back()->with('success', 'Torre actualizada exitosamente.');
    }

    public function storePiso(SavePisoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $this->savePiso->execute($edificio->id, null, $request->validated());

        return back()->with('success', 'Piso creado exitosamente.');
    }

    public function updatePiso(
        SavePisoRequest $request,
        EdificioEloquentModel $edificio,
        string $piso,
    ): RedirectResponse {
        $this->savePiso->execute($edificio->id, $piso, $request->validated());

        return back()->with('success', 'Piso actualizado exitosamente.');
    }

    public function storeParqueadero(SaveAnexoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $this->saveAnexo->execute(TipoAnexo::PARQUEADERO, $edificio->id, null, $request->validated());

        return back()->with('success', 'Parqueadero creado exitosamente.');
    }

    public function updateParqueadero(
        SaveAnexoRequest $request,
        EdificioEloquentModel $edificio,
        string $parqueadero,
    ): RedirectResponse {
        $this->saveAnexo->execute(TipoAnexo::PARQUEADERO, $edificio->id, $parqueadero, $request->validated());

        return back()->with('success', 'Parqueadero actualizado exitosamente.');
    }

    public function storeBodega(SaveAnexoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $this->saveAnexo->execute(TipoAnexo::BODEGA, $edificio->id, null, $request->validated());

        return back()->with('success', 'Bodega creada exitosamente.');
    }

    public function updateBodega(
        SaveAnexoRequest $request,
        EdificioEloquentModel $edificio,
        string $bodega,
    ): RedirectResponse {
        $this->saveAnexo->execute(TipoAnexo::BODEGA, $edificio->id, $bodega, $request->validated());

        return back()->with('success', 'Bodega actualizada exitosamente.');
    }

    public function changeElementStatus(
        ChangeEstructuraStatusRequest $request,
        EdificioEloquentModel $edificio,
        TipoElementoEstructura $tipo,
        string $elemento,
    ): RedirectResponse {
        $estado = EstadoEstructura::from($request->validated('estado'));
        $this->changeStatus->execute($tipo, $edificio->id, $elemento, $estado);

        return back()->with('success', 'Estado actualizado exitosamente.');
    }
}
