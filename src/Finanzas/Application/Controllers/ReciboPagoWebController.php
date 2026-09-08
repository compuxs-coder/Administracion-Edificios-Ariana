<?php

namespace Src\Finanzas\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Application\Actions\GetEvidenciaPagoDownloadAction;
use Src\Finanzas\Application\Actions\GetReciboPagoAction;
use Src\Finanzas\Application\Actions\StoreEvidenciaPagoAction;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Finanzas\Infrastructure\Requests\StoreEvidenciaPagoRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReciboPagoWebController extends Controller
{
    public function __construct(
        private readonly GetReciboPagoAction $getRecibo,
        private readonly StoreEvidenciaPagoAction $storeEvidencia,
        private readonly GetEvidenciaPagoDownloadAction $getEvidencia,
    ) {}

    public function show(Request $request, EdificioEloquentModel $edificio, PagoEloquentModel $pago): Response
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::COMPROBANTES_VER]);

        return Inertia::render('ReciboPago/show', ['recibo' => $this->getRecibo->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $pago->id)]);
    }

    public function storeEvidence(StoreEvidenciaPagoRequest $request, EdificioEloquentModel $edificio, PagoEloquentModel $pago): RedirectResponse
    {
        $this->storeEvidencia->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $pago->id, $request->validated());

        return back()->with('success', 'Evidencia de pago adjuntada correctamente.');
    }

    public function downloadEvidence(Request $request, EdificioEloquentModel $edificio, PagoEloquentModel $pago, string $evidencia): StreamedResponse
    {
        Gate::authorize('access', [$edificio, PermisoEdificio::COMPROBANTES_VER]);
        $documento = $this->getEvidencia->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $pago->id, $evidencia);
        return Storage::disk('evidence')->download($documento['path'], $documento['nombre'], [
            'Content-Type' => $documento['mimeType'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
