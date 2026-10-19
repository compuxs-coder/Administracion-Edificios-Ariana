<?php

namespace Src\Comunicacion\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;
use Src\Comunicacion\Domain\Contracts\DestinatariosComunicacionReadInterface;
use Src\Comunicacion\Domain\Enums\CanalNotificacionComunicado;
use Src\Comunicacion\Domain\Enums\EstadoComunicado;
use Src\Comunicacion\Domain\Enums\EstadoEntregaComunicado;
use Src\Comunicacion\Domain\Enums\TipoEventoComunicacion;
use Src\Comunicacion\Infrastructure\Models\BitacoraComunicacionEloquentModel;
use Src\Comunicacion\Infrastructure\Models\ComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\DestinatarioComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\DocumentoComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\EntregaComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\LimpiezaDocumentoComunicacionEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class EloquentComunicacionRepository implements ComunicacionRepositoryInterface
{
    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly DestinatariosComunicacionReadInterface $recipients,
    ) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::COMUNICADOS_VER);
        $deliveryBuildingIds = $this->access->buildingIds($userId, PermisoEdificio::COMUNICADOS_ENTREGAS_VER);
        $query = ComunicadoEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->withCount('documentos')
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $status) => $query->where('estado', $status))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $nested) => $nested
                    ->whereRaw('LOWER(titulo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(cuerpo) LIKE ?', [$term]));
            })
            ->orderByDesc(DB::raw('COALESCE(publicado_at, created_at)'))
            ->orderByDesc('id');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $buildingNames = EdificioEloquentModel::query()->whereKey($buildingIds)->pluck('nombre', 'id');

        return [
            'items' => $paginator->getCollection()->map(fn (ComunicadoEloquentModel $message): array => [
                ...$this->serializeMessage($message, in_array($message->edificio_id, $deliveryBuildingIds, true)),
                'edificio' => $buildingNames[$message->edificio_id] ?? 'Edificio no disponible',
                'documentosCount' => (int) ($message->documentos_count ?? 0),
            ])->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function options(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array
    {
        $buildingIds = $buildingId === null
            ? $this->access->buildingIds($userId, $permission)
            : [$this->authorizedBuilding($userId, $buildingId, $permission)->id];

        return [
            'edificios' => EdificioEloquentModel::query()->whereKey($buildingIds)
                ->when($permission !== PermisoEdificio::COMUNICADOS_VER, static fn (Builder $query) => $query->where('estado', 'activo'))
                ->orderBy('nombre')->get(['id', 'nombre', 'estado', 'timezone'])
                ->map(static fn (EdificioEloquentModel $building): array => [
                    'id' => $building->id,
                    'nombre' => $building->nombre,
                    'estado' => $building->estado->value,
                    'timezone' => $building->timezone,
                ])->all(),
        ];
    }

    public function get(string $userId, string $buildingId, string $messageId, int $deliveryPage = 1, int $omittedPage = 1): array
    {
        $building = $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_VER);
        $message = ComunicadoEloquentModel::query()->where('edificio_id', $buildingId)
            ->with([
                'documentos' => static fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'bitacora' => static fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ])->findOrFail($messageId);
        $canManage = $this->access->hasPermission($userId, $buildingId, PermisoEdificio::COMUNICADOS_GESTIONAR);
        $canPublish = $this->access->hasPermission($userId, $buildingId, PermisoEdificio::COMUNICADOS_PUBLICAR);
        $canViewDeliveries = $this->access->hasPermission($userId, $buildingId, PermisoEdificio::COMUNICADOS_ENTREGAS_VER);
        $actorIds = $message->bitacora->pluck('actor_user_id')->unique()->values()->all();
        $actorNames = $this->actorNames($actorIds);
        $result = [
            ...$this->serializeMessage($message, $canViewDeliveries),
            'edificio' => $building->nombre,
            'timezone' => $building->timezone,
            'puedeGestionar' => $canManage,
            'puedePublicar' => $canPublish,
            'puedeVerEntregas' => $canViewDeliveries,
            'documentos' => $message->documentos->map(fn (DocumentoComunicadoEloquentModel $document): array => $this->serializeDocument($document))->all(),
            'vistaPrevia' => $message->estado === EstadoComunicado::BORRADOR && $canPublish
                ? $this->previewFromCandidates($this->resolveCandidates($message))
                : null,
            'bitacora' => $message->bitacora->map(fn (BitacoraComunicacionEloquentModel $entry): array => [
                'id' => $entry->id,
                'tipo' => $entry->tipo->value,
                'actorNombre' => $actorNames[$entry->actor_user_id] ?? 'Usuario no disponible',
                'detalle' => $this->visibleEventDetail($entry, $canManage, $canViewDeliveries),
                'createdAt' => $entry->created_at?->toIso8601String(),
            ])->all(),
        ];
        if ($canViewDeliveries) {
            $pageSize = max(1, min(250, (int) config('comunicacion.trace_page_size')));
            $deliveryCounts = EntregaComunicadoEloquentModel::query()->where('comunicado_id', $message->id)
                ->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
            $result['resumenEntregas'] = collect(EstadoEntregaComunicado::cases())->mapWithKeys(
                static fn (EstadoEntregaComunicado $status): array => [$status->value => (int) ($deliveryCounts[$status->value] ?? 0)],
            )->all();
            $deliveries = EntregaComunicadoEloquentModel::query()->where('comunicado_id', $message->id)
                ->withCount('destinatarios')->orderByRaw("CASE estado WHEN 'fallida' THEN 0 ELSE 1 END")
                ->orderBy('destino_normalizado')->paginate($pageSize, ['*'], 'entregas_page', max(1, $deliveryPage));
            $result['entregas'] = $deliveries->getCollection()
                ->map(static fn (EntregaComunicadoEloquentModel $delivery): array => [
                    'id' => $delivery->id,
                    'canal' => $delivery->canal->value,
                    'destino' => $delivery->destino_normalizado,
                    'estado' => $delivery->estado->value,
                    'intentos' => (int) $delivery->intentos,
                    'destinatariosCount' => (int) ($delivery->destinatarios_count ?? 0),
                    'ultimoIntentoAt' => $delivery->ultimo_intento_at?->toIso8601String(),
                    'enviadoAt' => $delivery->enviado_at?->toIso8601String(),
                    'errorCodigo' => $delivery->error_codigo,
                    'errorDetalle' => $delivery->error_detalle,
                ])->all();
            $result['entregasMeta'] = $this->paginationMeta($deliveries);
            $omitted = DestinatarioComunicadoEloquentModel::query()->where('comunicado_id', $message->id)
                ->where('estado', 'omitido')->orderBy('nombre_snapshot')->orderBy('id')
                ->paginate($pageSize, ['*'], 'omitidos_page', max(1, $omittedPage));
            $result['omitidos'] = $omitted->getCollection()
                ->map(static fn (DestinatarioComunicadoEloquentModel $recipient): array => [
                    'id' => $recipient->id,
                    'nombre' => $recipient->nombre_snapshot,
                    'email' => $recipient->email_snapshot,
                    'motivo' => $recipient->motivo_omision,
                ])->all();
            $result['omitidosMeta'] = $this->paginationMeta($omitted);
        }

        return $result;
    }

    public function create(string $userId, string $buildingId, array $data): array
    {
        return DB::transaction(function () use ($userId, $buildingId, $data): array {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_GESTIONAR, true, true);
            $message = ComunicadoEloquentModel::query()->create([
                'edificio_id' => $buildingId,
                ...$this->messageData($data),
                'estado' => EstadoComunicado::BORRADOR,
                'version' => 1,
                'creado_por_user_id' => $userId,
                'actualizado_por_user_id' => $userId,
            ]);
            $this->recordEvent($message, TipoEventoComunicacion::COMUNICADO_CREADO, $userId, [
                'audiencias' => $this->audiences($message),
            ]);

            return ['id' => $message->id];
        });
    }

    public function update(string $userId, string $buildingId, string $messageId, array $data): void
    {
        DB::transaction(function () use ($userId, $buildingId, $messageId, $data): void {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_GESTIONAR, true);
            $message = $this->lockedMessage($buildingId, $messageId);
            $this->assertDraft($message);
            $this->assertVersion($message, (string) $data['updated_at']);
            $before = $this->editableSnapshot($message);
            $message->fill([...$this->messageData($data), 'actualizado_por_user_id' => $userId]);
            $after = $this->editableSnapshot($message);
            $changes = $this->changes($before, $after);
            if ($changes === []) {
                return;
            }
            $this->advanceVersion($message);
            $message->save();
            $this->recordEvent($message, TipoEventoComunicacion::COMUNICADO_ACTUALIZADO, $userId, ['cambios' => $changes]);
        });
    }

    public function previewRecipients(string $userId, string $buildingId, string $messageId): array
    {
        $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_PUBLICAR);
        $message = ComunicadoEloquentModel::query()->where('edificio_id', $buildingId)->findOrFail($messageId);
        $this->assertDraft($message);

        return $this->previewFromCandidates($this->resolveCandidates($message));
    }

    public function publish(string $userId, string $buildingId, string $messageId, string $expectedUpdatedAt): array
    {
        return DB::transaction(function () use ($userId, $buildingId, $messageId, $expectedUpdatedAt): array {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_PUBLICAR, true, true);
            $message = $this->lockedMessage($buildingId, $messageId);
            $this->assertDraft($message);
            $this->assertVersion($message, $expectedUpdatedAt);
            $this->assertDocumentsReady($message);
            $now = CarbonImmutable::now()->startOfSecond();
            $resolved = $this->materializeRecipients($this->resolveCandidates($message, $now));
            if ($resolved['deliveries'] === []) {
                throw ValidationException::withMessages([
                    'destinatarios' => 'El comunicado no tiene destinatarios vigentes con un correo válido.',
                ]);
            }
            $message->fill([
                'estado' => EstadoComunicado::PUBLICADO,
                'actualizado_por_user_id' => $userId,
                'publicado_por_user_id' => $userId,
                'publicado_at' => $now,
                'total_candidatos' => count($resolved['recipients']),
                'total_entregas' => count($resolved['deliveries']),
                'total_omitidos' => $resolved['omitted'],
            ]);
            $this->advanceVersion($message, $now);
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            if (! $sqlite) {
                $message->save();
            }

            $deliveryIds = [];
            foreach ($resolved['deliveries'] as $email => $_recipientIndexes) {
                $delivery = EntregaComunicadoEloquentModel::query()->create([
                    'edificio_id' => $buildingId,
                    'comunicado_id' => $message->id,
                    'canal' => CanalNotificacionComunicado::EMAIL,
                    'destino_normalizado' => $email,
                    'estado' => EstadoEntregaComunicado::PENDIENTE,
                    'intentos' => 0,
                    'disponible_at' => $now,
                ]);
                $deliveryIds[$email] = $delivery->id;
            }
            foreach ($resolved['recipients'] as $recipient) {
                DestinatarioComunicadoEloquentModel::query()->create([
                    'edificio_id' => $buildingId,
                    'comunicado_id' => $message->id,
                    'entrega_id' => $recipient['emailNormalizado'] === null ? null : $deliveryIds[$recipient['emailNormalizado']],
                    'tercero_id' => $recipient['terceroId'],
                    'nombre_snapshot' => $recipient['nombre'],
                    'email_snapshot' => $recipient['email'],
                    'email_normalizado' => $recipient['emailNormalizado'],
                    'es_propietario' => $recipient['esPropietario'],
                    'es_residente' => $recipient['esResidente'],
                    'departamentos_snapshot' => $recipient['departamentos'],
                    'estado' => $recipient['motivoOmision'] === null ? 'incluido' : 'omitido',
                    'motivo_omision' => $recipient['motivoOmision'],
                    'created_at' => $now,
                ]);
            }
            if ($sqlite) {
                $message->save();
            }
            $this->assertMaterializedSnapshot($message);
            $this->recordEvent($message, TipoEventoComunicacion::COMUNICADO_PUBLICADO, $userId, [
                'candidatos' => count($resolved['recipients']),
                'entregas' => count($resolved['deliveries']),
                'omitidos' => $resolved['omitted'],
                'duplicados' => $resolved['duplicates'],
                'fechaLocal' => $resolved['localDate'],
            ]);

            return ['id' => $message->id];
        });
    }

    public function archive(string $userId, string $buildingId, string $messageId): void
    {
        DB::transaction(function () use ($userId, $buildingId, $messageId): void {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_PUBLICAR, true);
            $message = $this->lockedMessage($buildingId, $messageId);
            if ($message->estado !== EstadoComunicado::PUBLICADO) {
                throw ValidationException::withMessages(['estado' => 'Sólo un comunicado publicado puede archivarse.']);
            }
            $now = $this->transitionTime($message->publicado_at);
            $message->fill([
                'estado' => EstadoComunicado::ARCHIVADO,
                'actualizado_por_user_id' => $userId,
                'archivado_por_user_id' => $userId,
                'archivado_at' => $now,
            ]);
            $this->advanceVersion($message, $now);
            $message->save();
            $this->recordEvent($message, TipoEventoComunicacion::COMUNICADO_ARCHIVADO, $userId);
        });
    }

    public function storeDocument(string $userId, string $buildingId, string $messageId, UploadedFile $file, ?string $description): array
    {
        $this->assertFilesystemMutationOwnsTransaction();
        $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_GESTIONAR);
        $message = ComunicadoEloquentModel::query()->where('edificio_id', $buildingId)->findOrFail($messageId);
        $this->assertDraft($message);
        [$mimeType, $size, $extension, $hash] = $this->validateDocument($file);
        $this->assertDocumentCapacity($messageId, $size);
        $documentId = (string) Str::uuid();
        $directory = 'comunicacion/'.$buildingId.'/'.$messageId;
        $path = $directory.'/'.$documentId.'.'.$extension;
        $diskName = (string) config('comunicacion.document_disk');
        $disk = $this->privateDocumentDisk($diskName);
        $cleanup = LimpiezaDocumentoComunicacionEloquentModel::query()->create([
            'disco' => $diskName,
            'ruta_privada' => $path,
            'created_at' => CarbonImmutable::now(),
        ]);

        try {
            $stored = $disk->putFileAs($directory, $file, $documentId.'.'.$extension);
            if ($stored === false || $stored !== $path) {
                throw new RuntimeException('No fue posible almacenar el documento del comunicado.');
            }
            $storedContents = $disk->get($path);
            if (strlen($storedContents) !== $size || ! hash_equals($hash, hash('sha256', $storedContents))) {
                throw new RuntimeException('El documento almacenado no coincide con el archivo recibido.');
            }

            return DB::transaction(function () use ($userId, $buildingId, $messageId, $file, $description, $path, $mimeType, $size, $extension, $hash, $documentId, $diskName, $cleanup): array {
                $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_GESTIONAR, true);
                $message = $this->lockedMessage($buildingId, $messageId);
                $this->assertDraft($message);
                $this->assertDocumentCapacity($messageId, $size);
                $document = new DocumentoComunicadoEloquentModel();
                $document->id = $documentId;
                $document->fill([
                    'edificio_id' => $buildingId,
                    'comunicado_id' => $message->id,
                    'disco' => $diskName,
                    'nombre_original' => $this->safeFilename($file->getClientOriginalName(), $extension),
                    'mime_type' => $mimeType,
                    'tamano_bytes' => $size,
                    'sha256' => $hash,
                    'ruta_privada' => $path,
                    'descripcion' => $this->nullableTrim($description),
                    'subido_por_user_id' => $userId,
                    'created_at' => CarbonImmutable::now(),
                ])->save();
                $message->actualizado_por_user_id = $userId;
                $this->advanceVersion($message);
                $message->save();
                $this->recordEvent($message, TipoEventoComunicacion::DOCUMENTO_AGREGADO, $userId, [
                    'documentoId' => $document->id,
                    'nombre' => $document->nombre_original,
                    'mimeType' => $mimeType,
                    'tamanoBytes' => $size,
                ]);
                LimpiezaDocumentoComunicacionEloquentModel::query()->whereKey($cleanup->id)->delete();

                return ['id' => $document->id];
            });
        } catch (\Throwable $exception) {
            try {
                if (! $disk->exists($path) || $disk->delete($path)) {
                    LimpiezaDocumentoComunicacionEloquentModel::query()->whereKey($cleanup->id)->delete();
                }
            } catch (\Throwable) {
                // Preserve the storage or transaction exception that caused the rollback.
            }
            throw $exception;
        }
    }

    public function deleteDocument(string $userId, string $buildingId, string $messageId, string $documentId): void
    {
        $this->assertFilesystemMutationOwnsTransaction();
        DB::transaction(function () use ($userId, $buildingId, $messageId, $documentId): void {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_GESTIONAR, true);
            $message = $this->lockedMessage($buildingId, $messageId);
            $this->assertDraft($message);
            $document = DocumentoComunicadoEloquentModel::query()->where('edificio_id', $buildingId)
                ->where('comunicado_id', $messageId)->lockForUpdate()->findOrFail($documentId);
            $snapshot = ['documentoId' => $document->id, 'nombre' => $document->nombre_original];
            $this->privateDocumentDisk($document->disco);
            $cleanup = LimpiezaDocumentoComunicacionEloquentModel::query()->firstOrCreate([
                'disco' => $document->disco,
                'ruta_privada' => $document->ruta_privada,
            ], ['created_at' => CarbonImmutable::now()]);
            $document->delete();
            $message->actualizado_por_user_id = $userId;
            $this->advanceVersion($message);
            $message->save();
            $this->recordEvent($message, TipoEventoComunicacion::DOCUMENTO_RETIRADO, $userId, $snapshot);
            DB::afterCommit(fn () => $this->deleteTrackedDocumentFile(
                $document->disco,
                $document->ruta_privada,
                $cleanup->id,
            ));
        });
    }

    public function documentDownload(string $userId, string $buildingId, string $messageId, string $documentId): array
    {
        $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_VER);
        $document = DocumentoComunicadoEloquentModel::query()->where('edificio_id', $buildingId)
            ->where('comunicado_id', $messageId)->findOrFail($documentId);

        return $this->verifiedDocument($document);
    }

    public function signedDocumentDownload(string $deliveryId, string $documentId): array
    {
        $delivery = EntregaComunicadoEloquentModel::query()->where('estado', '<>', EstadoEntregaComunicado::PENDIENTE->value)
            ->findOrFail($deliveryId);
        ComunicadoEloquentModel::query()->where('edificio_id', $delivery->edificio_id)
            ->whereIn('estado', [EstadoComunicado::PUBLICADO, EstadoComunicado::ARCHIVADO])
            ->findOrFail($delivery->comunicado_id);
        $document = DocumentoComunicadoEloquentModel::query()->where('edificio_id', $delivery->edificio_id)
            ->where('comunicado_id', $delivery->comunicado_id)->findOrFail($documentId);

        return $this->verifiedDocument($document);
    }

    public function retryDelivery(string $userId, string $buildingId, string $messageId, string $deliveryId): void
    {
        DB::transaction(function () use ($userId, $buildingId, $messageId, $deliveryId): void {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::COMUNICADOS_PUBLICAR, true);
            $message = ComunicadoEloquentModel::query()->where('edificio_id', $buildingId)
                ->whereIn('estado', [EstadoComunicado::PUBLICADO, EstadoComunicado::ARCHIVADO])
                ->findOrFail($messageId);
            $delivery = EntregaComunicadoEloquentModel::query()->where('edificio_id', $buildingId)
                ->where('comunicado_id', $messageId)->lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->estado !== EstadoEntregaComunicado::FALLIDA) {
                throw ValidationException::withMessages(['entrega' => 'Sólo una entrega fallida puede reintentarse manualmente.']);
            }
            $delivery->fill([
                'estado' => EstadoEntregaComunicado::REINTENTO,
                'disponible_at' => CarbonImmutable::now(),
                'lease_until' => null,
            ])->save();
            $this->recordEvent($message, TipoEventoComunicacion::ENTREGA_REINTENTADA, $userId, [
                'entregaId' => $delivery->id,
                'intentosPrevios' => (int) $delivery->intentos,
            ]);
        });
    }

    public function dueDeliveryIds(?string $messageId = null, int $limit = 500): array
    {
        $now = CarbonImmutable::now();

        return EntregaComunicadoEloquentModel::query()
            ->when($messageId, static fn (Builder $query, string $id) => $query->where('comunicado_id', $id))
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $due) => $due->whereIn('estado', [EstadoEntregaComunicado::PENDIENTE, EstadoEntregaComunicado::REINTENTO])->where('disponible_at', '<=', $now))
                ->orWhere(static fn (Builder $expired) => $expired->where('estado', EstadoEntregaComunicado::PROCESANDO)->where('lease_until', '<=', $now)))
            ->orderBy('disponible_at')->orderBy('id')->limit(max(1, min($limit, 1000)))->pluck('id')->all();
    }

    public function claimDelivery(string $deliveryId): ?array
    {
        return DB::transaction(function () use ($deliveryId): ?array {
            $delivery = EntregaComunicadoEloquentModel::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || in_array($delivery->estado, [EstadoEntregaComunicado::ENVIADA, EstadoEntregaComunicado::FALLIDA], true)) {
                return null;
            }
            $now = CarbonImmutable::now()->startOfSecond();
            if (in_array($delivery->estado, [EstadoEntregaComunicado::PENDIENTE, EstadoEntregaComunicado::REINTENTO], true)
                && $delivery->disponible_at?->isAfter($now)) {
                return null;
            }
            if ($delivery->estado === EstadoEntregaComunicado::PROCESANDO && $delivery->lease_until?->isAfter($now)) {
                return null;
            }
            if ($delivery->estado === EstadoEntregaComunicado::PROCESANDO
                && (int) $delivery->intentos >= (int) config('comunicacion.max_delivery_attempts')) {
                $delivery->fill([
                    'estado' => EstadoEntregaComunicado::FALLIDA,
                    'disponible_at' => $now,
                    'lease_until' => null,
                    'error_codigo' => 'LeaseExpirado',
                    'error_detalle' => 'El worker no confirmó el último intento antes de vencer su lease.',
                ])->save();

                return null;
            }
            $delivery->fill([
                'estado' => EstadoEntregaComunicado::PROCESANDO,
                'intentos' => (int) $delivery->intentos + 1,
                'lease_until' => $now->addSeconds((int) config('comunicacion.delivery_lease_seconds')),
                'ultimo_intento_at' => $now,
                'error_codigo' => null,
                'error_detalle' => null,
            ])->save();
            $message = ComunicadoEloquentModel::query()->whereIn('estado', [EstadoComunicado::PUBLICADO, EstadoComunicado::ARCHIVADO])
                ->with(['documentos' => static fn ($query) => $query->orderBy('created_at')])->findOrFail($delivery->comunicado_id);
            $building = EdificioEloquentModel::query()->findOrFail($delivery->edificio_id, ['id', 'nombre']);
            $names = DestinatarioComunicadoEloquentModel::query()->where('entrega_id', $delivery->id)
                ->orderBy('nombre_snapshot')->pluck('nombre_snapshot')->all();

            return [
                'id' => $delivery->id,
                'attempt' => (int) $delivery->intentos,
                'channel' => $delivery->canal->value,
                'destination' => $delivery->destino_normalizado,
                'title' => $message->titulo,
                'body' => $message->cuerpo,
                'building' => $building->nombre,
                'recipientNames' => $names,
                'documents' => $message->documentos->map(static fn (DocumentoComunicadoEloquentModel $document): array => [
                    'id' => $document->id,
                    'name' => $document->nombre_original,
                ])->all(),
            ];
        });
    }

    public function markDeliverySent(string $deliveryId, int $attempt, ?string $providerMessageId = null): void
    {
        DB::transaction(function () use ($deliveryId, $attempt, $providerMessageId): void {
            $delivery = EntregaComunicadoEloquentModel::query()->lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->estado !== EstadoEntregaComunicado::PROCESANDO || (int) $delivery->intentos !== $attempt) {
                return;
            }
            $delivery->fill([
                'estado' => EstadoEntregaComunicado::ENVIADA,
                'lease_until' => null,
                'enviado_at' => CarbonImmutable::now(),
                'proveedor_mensaje_id' => $providerMessageId,
                'error_codigo' => null,
                'error_detalle' => null,
            ])->save();
        });
    }

    public function markDeliveryFailure(string $deliveryId, int $attempt, \Throwable $exception): void
    {
        DB::transaction(function () use ($deliveryId, $attempt, $exception): void {
            $delivery = EntregaComunicadoEloquentModel::query()->lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->estado !== EstadoEntregaComunicado::PROCESANDO || (int) $delivery->intentos !== $attempt) {
                return;
            }
            $maximum = (int) config('comunicacion.max_delivery_attempts');
            $terminal = $attempt >= $maximum;
            $backoff = match ($attempt) {
                1 => 60,
                2 => 300,
                default => 900,
            };
            $delivery->fill([
                'estado' => $terminal ? EstadoEntregaComunicado::FALLIDA : EstadoEntregaComunicado::REINTENTO,
                'disponible_at' => CarbonImmutable::now()->addSeconds($terminal ? 0 : $backoff),
                'lease_until' => null,
                'error_codigo' => class_basename($exception),
                'error_detalle' => Str::limit(trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? ''), 1000, ''),
            ])->save();
        });
    }

    private function authorizedBuilding(string $userId, string $buildingId, PermisoEdificio $permission, bool $lock = false, bool $active = false): EdificioEloquentModel
    {
        if ($lock) {
            $building = EdificioEloquentModel::query()->lockForUpdate()->findOrFail($buildingId);
            abort_unless($this->access->hasPermission($userId, $buildingId, $permission), 403);
        } else {
            $building = EdificioEloquentModel::query()->whereKey($this->access->buildingIds($userId, $permission))->findOrFail($buildingId);
        }
        if ($active && $building->estado->value !== 'activo') {
            throw ValidationException::withMessages(['edificio_id' => 'El edificio debe estar activo.']);
        }

        return $building;
    }

    private function lockedMessage(string $buildingId, string $messageId): ComunicadoEloquentModel
    {
        return ComunicadoEloquentModel::query()->where('edificio_id', $buildingId)->lockForUpdate()->findOrFail($messageId);
    }

    private function assertDraft(ComunicadoEloquentModel $message): void
    {
        if ($message->estado !== EstadoComunicado::BORRADOR) {
            throw ValidationException::withMessages(['estado' => 'El comunicado publicado es inmutable.']);
        }
    }

    private function assertVersion(ComunicadoEloquentModel $message, string $expected): void
    {
        if ($message->updated_at === null || ! $message->updated_at->equalTo(CarbonImmutable::parse($expected))) {
            throw ValidationException::withMessages(['updated_at' => 'El comunicado cambió desde que abrió la vista. Recargue antes de continuar.']);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function messageData(array $data): array
    {
        $audiences = $data['audiencias'];

        return [
            'titulo' => trim($data['titulo']),
            'cuerpo' => trim($data['cuerpo']),
            'audiencia_propietarios' => in_array('propietarios', $audiences, true),
            'audiencia_residentes' => in_array('residentes', $audiences, true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resolveCandidates(ComunicadoEloquentModel $message, ?CarbonImmutable $instant = null): array
    {
        $building = EdificioEloquentModel::query()->findOrFail($message->edificio_id, ['id', 'timezone']);
        $localDate = ($instant ?? CarbonImmutable::now())->setTimezone($building->timezone)->format('Y-m-d');

        return array_map(static fn (array $candidate): array => [...$candidate, 'localDate' => $localDate], $this->recipients->resolve(
            $message->edificio_id,
            $localDate,
            $message->audiencia_propietarios,
            $message->audiencia_residentes,
        ));
    }

    /** @param list<array<string, mixed>> $candidates @return array<string, mixed> */
    private function materializeRecipients(array $candidates): array
    {
        $recipients = [];
        $deliveries = [];
        $omitted = 0;
        foreach ($candidates as $index => $candidate) {
            $rawEmail = trim((string) ($candidate['email'] ?? ''));
            $normalized = $rawEmail === '' ? null : mb_strtolower($rawEmail);
            $reason = null;
            if ($normalized === null) {
                $reason = 'sin_correo';
            } elseif (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
                $normalized = null;
                $reason = 'correo_invalido';
            }
            if ($reason !== null) {
                $omitted++;
            } else {
                $deliveries[$normalized][] = $index;
            }
            $recipients[] = [
                ...$candidate,
                'email' => $rawEmail === '' ? null : $rawEmail,
                'emailNormalizado' => $normalized,
                'motivoOmision' => $reason,
            ];
        }
        $included = count($recipients) - $omitted;

        return [
            'recipients' => $recipients,
            'deliveries' => $deliveries,
            'omitted' => $omitted,
            'duplicates' => max(0, $included - count($deliveries)),
            'localDate' => $candidates[0]['localDate'] ?? CarbonImmutable::today()->format('Y-m-d'),
        ];
    }

    /** @param list<array<string, mixed>> $candidates @return array<string, int> */
    private function previewFromCandidates(array $candidates): array
    {
        $resolved = $this->materializeRecipients($candidates);

        return [
            'candidatos' => count($resolved['recipients']),
            'propietarios' => count(array_filter($resolved['recipients'], static fn (array $recipient): bool => $recipient['esPropietario'])),
            'residentes' => count(array_filter($resolved['recipients'], static fn (array $recipient): bool => $recipient['esResidente'])),
            'entregas' => count($resolved['deliveries']),
            'omitidos' => $resolved['omitted'],
            'duplicados' => $resolved['duplicates'],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMessage(ComunicadoEloquentModel $message, bool $includeDeliveryTotals): array
    {
        return [
            'id' => $message->id,
            'edificioId' => $message->edificio_id,
            'titulo' => $message->titulo,
            'cuerpo' => $message->cuerpo,
            'audiencias' => $this->audiences($message),
            'estado' => $message->estado->value,
            'version' => (int) $message->version,
            ...($includeDeliveryTotals ? [
                'totalCandidatos' => (int) $message->total_candidatos,
                'totalEntregas' => (int) $message->total_entregas,
                'totalOmitidos' => (int) $message->total_omitidos,
            ] : []),
            'publicadoAt' => $message->publicado_at?->toIso8601String(),
            'archivadoAt' => $message->archivado_at?->toIso8601String(),
            'createdAt' => $message->created_at?->toIso8601String(),
            'updatedAt' => $message->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function audiences(ComunicadoEloquentModel $message): array
    {
        return array_values(array_filter([
            $message->audiencia_propietarios ? 'propietarios' : null,
            $message->audiencia_residentes ? 'residentes' : null,
        ]));
    }

    /** @return array<string, mixed> */
    private function serializeDocument(DocumentoComunicadoEloquentModel $document): array
    {
        return [
            'id' => $document->id,
            'nombre' => $document->nombre_original,
            'mimeType' => $document->mime_type,
            'tamanoBytes' => (int) $document->tamano_bytes,
            'descripcion' => $document->descripcion,
            'createdAt' => $document->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function editableSnapshot(ComunicadoEloquentModel $message): array
    {
        return ['titulo' => $message->titulo, 'cuerpo' => $message->cuerpo, 'audiencias' => $this->audiences($message)];
    }

    /** @return array<string, mixed>|null */
    private function visibleEventDetail(BitacoraComunicacionEloquentModel $entry, bool $canManage, bool $canViewDeliveries): ?array
    {
        $detail = $entry->detalle;
        if (! $canViewDeliveries && $entry->tipo === TipoEventoComunicacion::COMUNICADO_PUBLICADO) {
            return isset($detail['fechaLocal']) ? ['fechaLocal' => $detail['fechaLocal']] : null;
        }
        if ($canManage || $entry->tipo !== TipoEventoComunicacion::COMUNICADO_ACTUALIZADO || ! is_array($detail['cambios'] ?? null)) {
            return $detail;
        }
        $detail['cambios'] = array_fill_keys(array_keys($detail['cambios']), true);

        return $detail;
    }

    private function assertMaterializedSnapshot(ComunicadoEloquentModel $message): void
    {
        $recipients = DestinatarioComunicadoEloquentModel::query()->where('comunicado_id', $message->id);
        if ((clone $recipients)->count() !== (int) $message->total_candidatos
            || EntregaComunicadoEloquentModel::query()->where('comunicado_id', $message->id)->count() !== (int) $message->total_entregas
            || (clone $recipients)->where('estado', 'omitido')->count() !== (int) $message->total_omitidos) {
            throw new RuntimeException('El snapshot publicado no coincide con los totales calculados.');
        }
    }

    private function recordEvent(ComunicadoEloquentModel $message, TipoEventoComunicacion $type, string $actorId, ?array $detail = null): void
    {
        BitacoraComunicacionEloquentModel::query()->create([
            'edificio_id' => $message->edificio_id,
            'comunicado_id' => $message->id,
            'tipo' => $type,
            'actor_user_id' => $actorId,
            'detalle' => $detail,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function advanceVersion(ComunicadoEloquentModel $message, ?CarbonImmutable $time = null): void
    {
        $message->version = (int) $message->version + 1;
        $now = ($time ?? CarbonImmutable::now())->startOfSecond();
        $message->updated_at = $message->updated_at !== null && $now->lessThanOrEqualTo($message->updated_at)
            ? CarbonImmutable::instance($message->updated_at)->addSecond()
            : $now;
    }

    private function transitionTime(mixed $previous): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return $previous !== null && $now->lessThanOrEqualTo($previous)
            ? CarbonImmutable::instance($previous)->addSecond()
            : $now;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, array<string, mixed>> */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $field => $value) {
            if (json_encode($value) !== json_encode($after[$field])) {
                $changes[$field] = ['anterior' => $value, 'nuevo' => $after[$field]];
            }
        }

        return $changes;
    }

    /** @return array{string, int, string, string} */
    private function validateDocument(UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $extension = match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw ValidationException::withMessages(['archivo' => 'El archivo debe ser PDF, JPG o PNG.']),
        };
        if ($size <= 0 || $size > (int) config('comunicacion.max_document_bytes')) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no puede superar 10 MB.']);
        }
        $path = $file->getRealPath();
        if ($mimeType === 'application/pdf') {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw ValidationException::withMessages(['archivo' => 'No fue posible leer el PDF.']);
            }
            $header = fread($handle, 5);
            fseek($handle, -min($size, 2048), SEEK_END);
            $tail = stream_get_contents($handle);
            $valid = $header === '%PDF-' && $tail !== false && preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $tail, $matches) === 1;
            if ($valid) {
                $offset = (int) $matches[1];
                $valid = $offset >= 0 && $offset < $size && fseek($handle, $offset) === 0;
                $xref = $valid ? fread($handle, 2048) : false;
                $valid = $xref !== false && (str_starts_with(ltrim($xref), 'xref') || preg_match('/\/Type\s*\/XRef\b/', $xref) === 1);
            }
            fclose($handle);
            if (! $valid) {
                throw ValidationException::withMessages(['archivo' => 'El PDF no tiene una estructura válida.']);
            }
        } else {
            $image = @getimagesize($path);
            $expected = $mimeType === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
            if ($image === false || ($image[2] ?? null) !== $expected) {
                throw ValidationException::withMessages(['archivo' => 'La imagen no tiene una estructura válida.']);
            }
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('No fue posible verificar el documento del comunicado.');
        }

        return [$mimeType, $size, $extension, $hash];
    }

    private function assertDocumentCapacity(string $messageId, int $incomingBytes): void
    {
        $query = DocumentoComunicadoEloquentModel::query()->where('comunicado_id', $messageId);
        if ((clone $query)->count() >= (int) config('comunicacion.max_documents')
            || (int) $query->sum('tamano_bytes') + $incomingBytes > (int) config('comunicacion.max_total_document_bytes')) {
            throw ValidationException::withMessages(['archivo' => 'El comunicado alcanzó el límite de 10 documentos o 10 MB acumulados.']);
        }
    }

    private function assertDocumentsReady(ComunicadoEloquentModel $message): void
    {
        $documents = DocumentoComunicadoEloquentModel::query()->where('comunicado_id', $message->id)->lockForUpdate()->get();
        foreach ($documents as $document) {
            $disk = $this->privateDocumentDisk($document->disco);
            if (! $disk->exists($document->ruta_privada)) {
                throw ValidationException::withMessages(['documentos' => 'Un documento adjunto ya no está disponible. Retírelo o vuelva a cargarlo antes de publicar.']);
            }
            $contents = $disk->get($document->ruta_privada);
            if (strlen($contents) !== (int) $document->tamano_bytes || ! hash_equals($document->sha256, hash('sha256', $contents))) {
                throw ValidationException::withMessages(['documentos' => 'Un documento adjunto no supera la verificación de integridad. Retírelo o vuelva a cargarlo antes de publicar.']);
            }
        }
    }

    /** @return array{contents: string, nombre: string, mimeType: string} */
    private function verifiedDocument(DocumentoComunicadoEloquentModel $document): array
    {
        $disk = $this->privateDocumentDisk($document->disco);
        abort_unless($disk->exists($document->ruta_privada), 404);
        $contents = $disk->get($document->ruta_privada);
        abort_unless(strlen($contents) === (int) $document->tamano_bytes, 409, 'El documento no supera la verificación de integridad.');
        abort_unless(hash_equals($document->sha256, hash('sha256', $contents)), 409, 'El documento no supera la verificación de integridad.');

        return ['contents' => $contents, 'nombre' => $document->nombre_original, 'mimeType' => $document->mime_type];
    }

    /** @return array{total: int, currentPage: int, lastPage: int, perPage: int} */
    private function paginationMeta(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    private function privateDocumentDisk(string $name): \Illuminate\Filesystem\FilesystemAdapter
    {
        $configuration = config("filesystems.disks.{$name}");
        if ($name === '' || mb_strlen($name) > 100 || ! is_array($configuration)
            || ($configuration['visibility'] ?? null) !== 'private' || ($configuration['serve'] ?? false) === true) {
            throw new RuntimeException('El disco de documentos de Comunicación debe ser privado y no servido.');
        }

        return Storage::disk($name);
    }

    private function assertFilesystemMutationOwnsTransaction(): void
    {
        if (DB::transactionLevel() !== 0 && ! app()->runningUnitTests()) {
            throw new RuntimeException('Las mutaciones de documentos de Comunicación deben administrar su propia transacción.');
        }
    }

    private function deleteTrackedDocumentFile(string $diskName, string $path, string $cleanupId): void
    {
        try {
            $disk = $this->privateDocumentDisk($diskName);
            if (! $disk->exists($path) || $disk->delete($path)) {
                LimpiezaDocumentoComunicacionEloquentModel::query()->whereKey($cleanupId)->delete();

                return;
            }
            throw new RuntimeException('El adaptador no confirmó la eliminación del archivo.');
        } catch (\Throwable $exception) {
            Log::warning('No se pudo eliminar un documento huérfano de Comunicación.', [
                'disco' => $diskName,
                'ruta' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeFilename(string $original, string $extension): string
    {
        $name = Str::ascii(pathinfo($original, PATHINFO_FILENAME));
        $name = preg_replace('/[^A-Za-z0-9_ -]+/', '_', $name) ?? '';
        $name = trim($name, " .-_\t\n\r\0\x0B");
        $name = Str::limit($name === '' ? 'documento' : $name, 180, '');

        return $name.'.'.$extension;
    }

    /** @param list<string> $ids @return array<string, string> */
    private function actorNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($this->usersTable())->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function usersTable(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'public.users' : 'users';
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
