<?php

namespace Src\Comunicacion\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Comunicacion\Application\Actions\ArchiveComunicadoAction;
use Src\Comunicacion\Application\Actions\CreateComunicadoAction;
use Src\Comunicacion\Application\Actions\DeleteDocumentoComunicadoAction;
use Src\Comunicacion\Application\Actions\GetComunicacionOptionsAction;
use Src\Comunicacion\Application\Actions\GetComunicadoAction;
use Src\Comunicacion\Application\Actions\GetDocumentoComunicadoDownloadAction;
use Src\Comunicacion\Application\Actions\ListComunicadosAction;
use Src\Comunicacion\Application\Actions\PreviewDestinatariosComunicadoAction;
use Src\Comunicacion\Application\Actions\PublishComunicadoAction;
use Src\Comunicacion\Application\Actions\RetryEntregaComunicadoAction;
use Src\Comunicacion\Application\Actions\StoreDocumentoComunicadoAction;
use Src\Comunicacion\Application\Actions\UpdateComunicadoAction;
use Src\Comunicacion\Domain\Enums\EstadoComunicado;
use Src\Comunicacion\Infrastructure\Requests\PublishComunicadoRequest;
use Src\Comunicacion\Infrastructure\Requests\SaveComunicadoRequest;
use Src\Comunicacion\Infrastructure\Requests\StoreDocumentoComunicadoRequest;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ComunicadoWebController extends Controller
{
    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly ListComunicadosAction $list,
        private readonly GetComunicadoAction $get,
        private readonly GetComunicacionOptionsAction $options,
        private readonly CreateComunicadoAction $create,
        private readonly UpdateComunicadoAction $update,
        private readonly PreviewDestinatariosComunicadoAction $preview,
        private readonly PublishComunicadoAction $publish,
        private readonly ArchiveComunicadoAction $archive,
        private readonly StoreDocumentoComunicadoAction $storeDocument,
        private readonly DeleteDocumentoComunicadoAction $deleteDocument,
        private readonly GetDocumentoComunicadoDownloadAction $downloadDocument,
        private readonly RetryEntregaComunicadoAction $retryDelivery,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::COMUNICADOS_VER), 403);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'edificio_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', Rule::enum(EstadoComunicado::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['buscar'] = trim((string) ($filters['buscar'] ?? '')) ?: null;
        $result = $this->list->execute($userId, $filters);

        return Inertia::render('Comunicado/index', [
            'comunicados' => ['data' => $result['items'], 'meta' => $this->meta($result)],
            'filters' => $filters,
            ...$this->options->execute($userId, PermisoEdificio::COMUNICADOS_VER),
        ]);
    }

    public function create(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::COMUNICADOS_GESTIONAR), 403);

        return Inertia::render('Comunicado/create', [
            ...$this->options->execute($userId, PermisoEdificio::COMUNICADOS_GESTIONAR),
            'edificioSeleccionado' => $request->validate(['edificio_id' => ['nullable', 'uuid']])['edificio_id'] ?? null,
        ]);
    }

    public function store(SaveComunicadoRequest $request, EdificioEloquentModel $edificio): RedirectResponse
    {
        $created = $this->create->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $request->validated());

        return redirect()->route('comunicados.show', [$edificio->id, $created['id']])
            ->with('success', 'Borrador creado. Revise los destinatarios antes de publicarlo.');
    }

    public function show(Request $request, EdificioEloquentModel $edificio, string $comunicado): Response
    {
        $pages = $request->validate([
            'entregas_page' => ['nullable', 'integer', 'min:1'],
            'omitidos_page' => ['nullable', 'integer', 'min:1'],
        ]);

        return Inertia::render('Comunicado/show', [
            'comunicado' => $this->get->execute(
                (string) $request->user()->getAuthIdentifier(),
                $edificio->id,
                $comunicado,
                (int) ($pages['entregas_page'] ?? 1),
                (int) ($pages['omitidos_page'] ?? 1),
            ),
        ]);
    }

    public function edit(Request $request, EdificioEloquentModel $edificio, string $comunicado): Response|RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allows($userId, $edificio->id, PermisoEdificio::COMUNICADOS_GESTIONAR), 403);
        $message = $this->get->execute($userId, $edificio->id, $comunicado);
        if ($message['estado'] !== EstadoComunicado::BORRADOR->value) {
            return redirect()->route('comunicados.show', [$edificio->id, $comunicado])
                ->with('error', 'Un comunicado publicado ya no puede editarse.');
        }

        return Inertia::render('Comunicado/edit', [
            'comunicado' => $message,
            ...$this->options->execute($userId, PermisoEdificio::COMUNICADOS_GESTIONAR, $edificio->id),
        ]);
    }

    public function update(SaveComunicadoRequest $request, EdificioEloquentModel $edificio, string $comunicado): RedirectResponse
    {
        $this->update->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $comunicado, $request->validated());

        return redirect()->route('comunicados.show', [$edificio->id, $comunicado])->with('success', 'Borrador actualizado.');
    }

    public function preview(Request $request, EdificioEloquentModel $edificio, string $comunicado): JsonResponse
    {
        return response()->json($this->preview->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $comunicado,
        ));
    }

    public function publish(PublishComunicadoRequest $request, EdificioEloquentModel $edificio, string $comunicado): RedirectResponse
    {
        $this->publish->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $comunicado,
            $request->validated('updated_at'),
        );

        return redirect()->route('comunicados.show', [$edificio->id, $comunicado])
            ->with('success', 'Comunicado publicado. Las entregas quedaron en cola.');
    }

    public function archive(Request $request, EdificioEloquentModel $edificio, string $comunicado): RedirectResponse
    {
        $this->archive->execute((string) $request->user()->getAuthIdentifier(), $edificio->id, $comunicado);

        return back()->with('success', 'Comunicado archivado.');
    }

    public function storeDocument(StoreDocumentoComunicadoRequest $request, EdificioEloquentModel $edificio, string $comunicado): RedirectResponse
    {
        $this->storeDocument->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $comunicado,
            $request->file('archivo'),
            $request->validated('descripcion'),
        );

        return back()->with('success', 'Documento privado adjuntado.');
    }

    public function deleteDocument(Request $request, EdificioEloquentModel $edificio, string $comunicado, string $documento): RedirectResponse
    {
        $this->deleteDocument->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $comunicado,
            $documento,
        );

        return back()->with('success', 'Documento retirado del borrador.');
    }

    public function downloadDocument(Request $request, EdificioEloquentModel $edificio, string $comunicado, string $documento): StreamedResponse
    {
        $document = $this->downloadDocument->authorized(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $comunicado,
            $documento,
        );

        return $this->downloadResponse($document);
    }

    public function signedDocument(string $documento, string $entrega): StreamedResponse
    {
        return $this->downloadResponse($this->downloadDocument->signed($entrega, $documento));
    }

    public function retryDelivery(Request $request, EdificioEloquentModel $edificio, string $comunicado, string $entrega): RedirectResponse
    {
        $this->retryDelivery->execute(
            (string) $request->user()->getAuthIdentifier(),
            $edificio->id,
            $comunicado,
            $entrega,
        );

        return back()->with('success', 'La entrega fallida volvió a la cola.');
    }

    /** @param array{contents: string, nombre: string, mimeType: string} $document */
    private function downloadResponse(array $document): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($document): void {
                echo $document['contents'];
            },
            $document['nombre'],
            ['Content-Type' => $document['mimeType'], 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** @param array<string, mixed> $result @return array<string, int> */
    private function meta(array $result): array
    {
        return ['total' => $result['total'], 'currentPage' => $result['currentPage'], 'lastPage' => $result['lastPage'], 'perPage' => $result['perPage']];
    }
}
