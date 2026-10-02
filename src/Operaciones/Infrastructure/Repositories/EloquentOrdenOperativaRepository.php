<?php

namespace Src\Operaciones\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Operaciones\Domain\Contracts\EstructuraMiembrosOperacionesReadInterface;
use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;
use Src\Operaciones\Domain\Contracts\ProveedoresOperacionesReadInterface;
use Src\Operaciones\Domain\Contracts\ReportantesOperacionesReadInterface;
use Src\Operaciones\Domain\Enums\EstadoOrdenOperativa;
use Src\Operaciones\Domain\Enums\TipoEventoOrdenOperativa;
use Src\Operaciones\Domain\Enums\TipoResponsableOrdenOperativa;
use Src\Operaciones\Infrastructure\Models\AsignacionOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\BitacoraOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\EvidenciaOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\OrdenOperativaEloquentModel;

final class EloquentOrdenOperativaRepository implements OrdenOperativaRepositoryInterface
{
    private const MAX_EVIDENCE_FILES_PER_ORDER = 100;

    private const MAX_EVIDENCE_BYTES_PER_ORDER = 500 * 1024 * 1024;

    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly EstructuraMiembrosOperacionesReadInterface $structure,
        private readonly ReportantesOperacionesReadInterface $reporters,
        private readonly ProveedoresOperacionesReadInterface $suppliers,
    ) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = OrdenOperativaEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::OPERACIONES_VER))
            ->with('asignacionActual')
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['tipo'] ?? null, static fn (Builder $query, string $type) => $query->where('tipo', $type))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['prioridad'] ?? null, static fn (Builder $query, string $priority) => $query->where('prioridad', $priority))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $nested) => $nested
                    ->whereRaw('LOWER(numero) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(titulo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', [$term]));
            })
            ->orderByDesc('created_at');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (OrdenOperativaEloquentModel $order): array => $this->serializeSummary($order))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $ordenId, bool $withHistory = true): array
    {
        $building = $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_VER);
        $query = OrdenOperativaEloquentModel::query()->where('edificio_id', $edificioId);
        if ($withHistory) {
            $query->with([
                'asignacionActual',
                'asignaciones' => static fn ($query) => $query->orderByDesc('fecha_inicio')->orderByDesc('id'),
                'evidencias' => static fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
                'bitacora' => static fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            ]);
        }
        $order = $query->findOrFail($ordenId);

        $canManage = $this->access->hasPermission($userId, $edificioId, PermisoEdificio::OPERACIONES_GESTIONAR);
        $result = $this->serialize($order, $withHistory, $canManage);
        $location = $this->structure->location($edificioId, $order->getAttributes());
        $result['edificio'] = $building->nombre;
        $result['ubicacion'] = [
            'tipo' => $location['tipo'] ?? null,
            'id' => $location['id'] ?? null,
            'etiqueta' => $location['etiqueta'] ?? null,
            'detalle' => $order->ubicacion_detalle,
        ];
        $result['proveedor'] = $order->proveedor_id === null
            ? null
            : $this->suppliers->provider($edificioId, $order->proveedor_id);
        $result['contrato'] = $order->contrato_id === null || $order->proveedor_id === null
            ? null
            : $this->suppliers->contract($edificioId, $order->proveedor_id, $order->contrato_id);
        if ($withHistory) {
            $actorIds = $order->bitacora->pluck('actor_user_id')->filter()->unique()->values()->all();
            $actorNames = $this->structure->memberNames($edificioId, $actorIds);
            $assignmentNames = $order->asignaciones->mapWithKeys(fn (AsignacionOrdenOperativaEloquentModel $assignment): array => [
                $assignment->id => $this->responsibleName($assignment->responsable_snapshot),
            ])->all();
            $result['bitacora'] = $order->bitacora->map(fn (BitacoraOrdenOperativaEloquentModel $entry): array => [
                'id' => $entry->id,
                'tipo' => $entry->tipo->value,
                'actorNombre' => $actorNames[$entry->actor_user_id] ?? 'Usuario no disponible',
                'detalle' => $this->safeEventDetail($entry, $assignmentNames),
                'createdAt' => $entry->created_at?->toIso8601String(),
            ])->all();
        }

        return $result;
    }

    public function options(string $userId, PermisoEdificio $permission, ?string $edificioId = null): array
    {
        $buildingIds = $edificioId === null
            ? $this->access->buildingIds($userId, $permission)
            : [$this->authorizedBuilding($userId, $edificioId, $permission)->id];

        return [
            ...$this->structure->options($buildingIds),
            'residentes' => $this->reporters->options($buildingIds),
            ...$this->suppliers->options($buildingIds),
        ];
    }

    public function assignmentOptions(string $userId, string $edificioId): array
    {
        $building = $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_ASIGNAR);

        return [
            'miembros' => $this->structure->members([$building->id]),
            'proveedores' => $this->suppliers->providers([$building->id]),
        ];
    }

    public function indexOptions(string $userId): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::OPERACIONES_VER);

        return ['edificios' => $this->structure->buildings($buildingIds)];
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_GESTIONAR, true);
            $this->structure->assertLocation($edificioId, $data);
            $reporter = $this->reporterSnapshot($edificioId, $data['reportante_residente_id'] ?? null, true);
            $providerId = $data['proveedor_id'] ?? null;
            if ($providerId !== null) {
                $this->activeProvider($edificioId, $providerId, true);
            }
            $this->registeredContract($edificioId, $providerId, $data['contrato_id'] ?? null, true);

            $order = new OrdenOperativaEloquentModel();
            $order->fill([
                'edificio_id' => $edificioId,
                'numero' => $this->nextNumber(),
                'tipo' => $data['tipo'],
                ...$this->persistenceData($data),
                'reportante_snapshot' => $reporter,
                'estado' => EstadoOrdenOperativa::REPORTADA,
                'creada_por_user_id' => $userId,
            ])->save();
            $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::CREACION, [
                'numero' => $order->numero,
                'datos' => $this->coreSnapshot($order),
            ]);

            return ['id' => $order->id, 'numero' => $order->numero];
        });
    }

    public function update(string $userId, string $edificioId, string $ordenId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $ordenId, $data): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_GESTIONAR, true);
            $order = $this->lockedOrder($edificioId, $ordenId);
            if (! $order->estado->permiteEditar()) {
                throw ValidationException::withMessages(['estado' => 'Los datos de la orden ya no se pueden editar en su estado actual.']);
            }
            $expectedUpdatedAt = CarbonImmutable::parse($data['updated_at']);
            if ($order->updated_at === null || ! $order->updated_at->equalTo($expectedUpdatedAt)) {
                throw ValidationException::withMessages(['updated_at' => 'La orden cambió desde que abrió el formulario. Recargue y revise los datos antes de guardar.']);
            }
            $this->structure->assertLocation($edificioId, $data, $order->getAttributes());

            $reporterId = $data['reportante_residente_id'] ?? null;
            $reporter = $reporterId === $order->reportante_residente_id
                ? $order->reportante_snapshot
                : $this->reporterSnapshot($edificioId, $reporterId, true);
            $providerId = $data['proveedor_id'] ?? null;
            $contractId = $data['contrato_id'] ?? null;
            if ($providerId !== null && $providerId !== $order->proveedor_id) {
                $this->activeProvider($edificioId, $providerId, true);
            }
            if ($contractId !== null && ($contractId !== $order->contrato_id || $providerId !== $order->proveedor_id)) {
                $this->registeredContract($edificioId, $providerId, $contractId, true);
            }
            $current = $this->currentAssignment($order, true);
            if ($current?->tipo_responsable === TipoResponsableOrdenOperativa::PROVEEDOR
                && $current->responsable_proveedor_id !== $providerId) {
                throw ValidationException::withMessages(['proveedor_id' => 'El proveedor asociado debe coincidir con el responsable vigente.']);
            }

            $before = $this->coreSnapshot($order);
            $order->fill([
                ...$this->persistenceData($data),
                'reportante_snapshot' => $reporter,
            ]);
            $after = $this->coreSnapshot($order);
            $changes = $this->changes($before, $after);
            if ($changes === []) {
                return;
            }
            $this->advanceOrderVersion($order);
            $order->save();
            $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::CAMBIO_DATOS, ['cambios' => $changes]);
        });
    }

    public function transition(string $userId, string $edificioId, string $ordenId, string $state): void
    {
        DB::transaction(function () use ($userId, $edificioId, $ordenId, $state): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_CAMBIAR_ESTADO, true);
            $order = $this->lockedOrder($edificioId, $ordenId);
            $target = EstadoOrdenOperativa::tryFrom($state);
            $expected = match ($order->estado) {
                EstadoOrdenOperativa::REPORTADA => EstadoOrdenOperativa::EN_REVISION,
                EstadoOrdenOperativa::EN_REVISION => EstadoOrdenOperativa::EN_PROGRESO,
                EstadoOrdenOperativa::EN_PROGRESO => EstadoOrdenOperativa::RESUELTA,
                EstadoOrdenOperativa::RESUELTA => EstadoOrdenOperativa::CERRADA,
                default => null,
            };
            if ($target === null || $target !== $expected) {
                throw ValidationException::withMessages(['estado' => 'La transición normal solicitada no está permitida.']);
            }
            if (in_array($target, [EstadoOrdenOperativa::EN_PROGRESO, EstadoOrdenOperativa::RESUELTA, EstadoOrdenOperativa::CERRADA], true)) {
                $this->assertActiveResponsibility($order);
            }

            $previous = $order->estado;
            $order->fill([
                'estado' => $target,
                'estado_actualizado_por' => $userId,
                'estado_actualizado_at' => $this->nextStateChangeTime($order),
                'motivo_estado' => null,
            ]);
            $this->advanceOrderVersion($order);
            $order->save();
            $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::CAMBIO_ESTADO, [
                'estadoAnterior' => $previous->value,
                'estadoNuevo' => $target->value,
            ]);
        });
    }

    public function assign(string $userId, string $edificioId, string $ordenId, string $type, string $responsibleId): void
    {
        DB::transaction(function () use ($userId, $edificioId, $ordenId, $type, $responsibleId): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_ASIGNAR, true);
            $order = $this->lockedOrder($edificioId, $ordenId);
            if ($order->estado === EstadoOrdenOperativa::CANCELADA) {
                throw ValidationException::withMessages(['estado' => 'Una orden cancelada no admite responsables.']);
            }
            $responsibleType = TipoResponsableOrdenOperativa::tryFrom($type);
            if ($responsibleType === null) {
                throw ValidationException::withMessages(['tipo_responsable' => 'El tipo de responsable no es válido.']);
            }

            if ($responsibleType === TipoResponsableOrdenOperativa::USUARIO) {
                $snapshot = $this->structure->activeMember($edificioId, $responsibleId, true);
                if ($snapshot === null) {
                    throw ValidationException::withMessages(['responsable_id' => 'El responsable interno debe ser miembro activo del edificio.']);
                }
            } else {
                $snapshot = $this->activeProvider($edificioId, $responsibleId, true);
                if ($order->proveedor_id === null) {
                    if (! $order->estado->permiteEditar()) {
                        throw ValidationException::withMessages(['proveedor_id' => 'Asocie el proveedor antes de cerrar o resolver la orden.']);
                    }
                    $order->proveedor_id = $responsibleId;
                    $this->advanceOrderVersion($order);
                    $order->save();
                } elseif ($order->proveedor_id !== $responsibleId) {
                    throw ValidationException::withMessages(['responsable_id' => 'El proveedor responsable no coincide con el asociado a la orden.']);
                }
            }

            $current = $this->currentAssignment($order, true);
            $currentId = $current?->tipo_responsable === TipoResponsableOrdenOperativa::USUARIO
                ? $current->responsable_user_id
                : $current?->responsable_proveedor_id;
            if ($current?->tipo_responsable === $responsibleType && $currentId === $responsibleId) {
                throw ValidationException::withMessages(['responsable_id' => 'La orden ya tiene este responsable vigente.']);
            }

            $now = CarbonImmutable::now();
            if ($current !== null) {
                $current->fill(['fecha_fin' => $now, 'finalizado_por_user_id' => $userId])->save();
            }
            $assignment = new AsignacionOrdenOperativaEloquentModel();
            $assignment->fill([
                'edificio_id' => $edificioId,
                'orden_operativa_id' => $order->id,
                'tipo_responsable' => $responsibleType,
                'responsable_user_id' => $responsibleType === TipoResponsableOrdenOperativa::USUARIO ? $responsibleId : null,
                'responsable_proveedor_id' => $responsibleType === TipoResponsableOrdenOperativa::PROVEEDOR ? $responsibleId : null,
                'responsable_snapshot' => $snapshot,
                'asignado_por_user_id' => $userId,
                'fecha_inicio' => $now,
            ])->save();
            $this->recordEvent(
                $order,
                $userId,
                $current === null ? TipoEventoOrdenOperativa::ASIGNACION : TipoEventoOrdenOperativa::REASIGNACION,
                [
                    'asignacionAnteriorId' => $current?->id,
                    'asignacionNuevaId' => $assignment->id,
                    'tipoResponsable' => $responsibleType->value,
                    'responsableNombre' => $this->responsibleName($snapshot),
                    'proveedorAsociadoAutomaticamente' => $responsibleType === TipoResponsableOrdenOperativa::PROVEEDOR
                        && $order->wasChanged('proveedor_id'),
                ],
            );
        });
    }

    public function cancel(string $userId, string $edificioId, string $ordenId, string $reason): void
    {
        DB::transaction(function () use ($userId, $edificioId, $ordenId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_CANCELAR, true);
            $order = $this->lockedOrder($edificioId, $ordenId);
            if (! in_array($order->estado, [EstadoOrdenOperativa::REPORTADA, EstadoOrdenOperativa::EN_REVISION, EstadoOrdenOperativa::EN_PROGRESO], true)) {
                throw ValidationException::withMessages(['estado' => 'La orden sólo puede cancelarse antes de ser resuelta.']);
            }
            $reason = trim($reason);
            $now = $this->nextStateChangeTime($order);
            $current = $this->currentAssignment($order, true);
            if ($current !== null) {
                $current->fill(['fecha_fin' => $now, 'finalizado_por_user_id' => $userId])->save();
            }
            $previous = $order->estado;
            $order->fill([
                'estado' => EstadoOrdenOperativa::CANCELADA,
                'estado_actualizado_por' => $userId,
                'estado_actualizado_at' => $now,
                'motivo_estado' => $reason,
            ]);
            $this->advanceOrderVersion($order);
            $order->save();
            $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::CANCELACION, [
                'estadoAnterior' => $previous->value,
                'motivo' => $reason,
                'asignacionFinalizadaId' => $current?->id,
            ]);
        });
    }

    public function reopen(string $userId, string $edificioId, string $ordenId, string $reason): void
    {
        DB::transaction(function () use ($userId, $edificioId, $ordenId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_REABRIR, true);
            $order = $this->lockedOrder($edificioId, $ordenId);
            if (! in_array($order->estado, [EstadoOrdenOperativa::RESUELTA, EstadoOrdenOperativa::CERRADA], true)) {
                throw ValidationException::withMessages(['estado' => 'Sólo una orden resuelta o cerrada puede reabrirse.']);
            }
            $this->assertActiveResponsibility($order);
            $previous = $order->estado;
            $reason = trim($reason);
            $order->fill([
                'estado' => EstadoOrdenOperativa::EN_PROGRESO,
                'estado_actualizado_por' => $userId,
                'estado_actualizado_at' => $this->nextStateChangeTime($order),
                'motivo_estado' => $reason,
            ]);
            $this->advanceOrderVersion($order);
            $order->save();
            $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::REAPERTURA, [
                'estadoAnterior' => $previous->value,
                'estadoNuevo' => EstadoOrdenOperativa::EN_PROGRESO->value,
                'motivo' => $reason,
            ]);
        });
    }

    public function recordManualAction(string $userId, string $edificioId, string $ordenId, string $description): void
    {
        DB::transaction(function () use ($userId, $edificioId, $ordenId, $description): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_GESTIONAR, true);
            $order = $this->lockedOrder($edificioId, $ordenId);
            if (! $order->estado->permiteAportes()) {
                throw ValidationException::withMessages(['estado' => 'La orden no admite nuevas actuaciones en su estado actual.']);
            }
            $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::ACTUACION_MANUAL, [
                'descripcion' => trim($description),
            ]);
        });
    }

    public function storeEvidence(string $userId, string $edificioId, string $ordenId, UploadedFile $file, ?string $description): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_GESTIONAR);
        $order = OrdenOperativaEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->findOrFail($ordenId);
        if (! $order->estado->permiteAportes()) {
            throw ValidationException::withMessages(['estado' => 'La orden no admite nuevas evidencias en su estado actual.']);
        }
        $mimeType = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $extension = match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw ValidationException::withMessages(['archivo' => 'El archivo debe ser PDF, JPG o PNG.']),
        };
        $this->validateFileContent($file, $mimeType, $size);
        $this->assertEvidenceCapacity($ordenId, $size);
        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            throw new RuntimeException('No fue posible verificar la evidencia operativa.');
        }
        $evidenceId = (string) Str::uuid();
        $directory = 'operaciones/'.$edificioId.'/'.$ordenId;
        $filename = $evidenceId.'.'.$extension;
        $path = $directory.'/'.$filename;
        try {
            $storedPath = Storage::disk('evidence')->putFileAs($directory, $file, $filename);
            if ($storedPath === false || $storedPath !== $path) {
                throw new RuntimeException('No fue posible almacenar la evidencia operativa.');
            }
            $absolutePath = Storage::disk('evidence')->path($path);
            if (Storage::disk('evidence')->size($path) !== $size
                || ! hash_equals($hash, (string) hash_file('sha256', $absolutePath))) {
                throw new RuntimeException('La evidencia almacenada no coincide con el archivo recibido.');
            }

            return DB::transaction(function () use (
                $userId, $edificioId, $ordenId, $file, $description, $path,
                $mimeType, $size, $extension, $hash, $evidenceId,
            ): array {
                $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_GESTIONAR, true);
                $order = $this->lockedOrder($edificioId, $ordenId);
                if (! $order->estado->permiteAportes()) {
                    throw ValidationException::withMessages(['estado' => 'La orden no admite nuevas evidencias en su estado actual.']);
                }
                $this->assertEvidenceCapacity($order->id, $size);

                $evidence = new EvidenciaOrdenOperativaEloquentModel();
                $evidence->id = $evidenceId;
                $evidence->fill([
                    'edificio_id' => $edificioId,
                    'orden_operativa_id' => $order->id,
                    'nombre_original' => $this->safeFilename($file->getClientOriginalName(), $extension),
                    'mime_type' => $mimeType,
                    'tamano_bytes' => $size,
                    'sha256' => $hash,
                    'ruta_privada' => $path,
                    'descripcion' => $this->nullableTrim($description),
                    'subido_por_user_id' => $userId,
                    'created_at' => CarbonImmutable::now(),
                ])->save();
                $this->recordEvent($order, $userId, TipoEventoOrdenOperativa::EVIDENCIA, [
                    'nombre' => $evidence->nombre_original,
                    'mimeType' => $mimeType,
                    'tamanoBytes' => $size,
                ]);

                return ['id' => $evidence->id];
            });
        } catch (\Throwable $exception) {
            if ($path !== null) {
                try {
                    Storage::disk('evidence')->delete($path);
                } catch (\Throwable) {
                    // Preserve the original storage or transaction failure.
                }
            }
            throw $exception;
        }
    }

    public function evidenceDownload(string $userId, string $edificioId, string $ordenId, string $evidenceId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::OPERACIONES_VER);
        $evidence = EvidenciaOrdenOperativaEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('orden_operativa_id', $ordenId)
            ->findOrFail($evidenceId);
        abort_unless(Storage::disk('evidence')->exists($evidence->ruta_privada), 404);
        $contents = Storage::disk('evidence')->get($evidence->ruta_privada);
        abort_unless(strlen($contents) === $evidence->tamano_bytes, 409, 'La evidencia no supera la verificación de integridad.');
        abort_unless(hash_equals($evidence->sha256, hash('sha256', $contents)), 409, 'La evidencia no supera la verificación de integridad.');

        return ['contents' => $contents, 'nombre' => $evidence->nombre_original, 'mimeType' => $evidence->mime_type];
    }

    private function authorizedBuilding(string $userId, string $buildingId, PermisoEdificio $permission, bool $lock = false): EdificioEloquentModel
    {
        if ($lock) {
            $building = EdificioEloquentModel::query()->lockForUpdate()->findOrFail($buildingId);
            abort_unless($this->access->hasPermission($userId, $buildingId, $permission), 403);

            return $building;
        }

        return EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->findOrFail($buildingId);
    }

    private function lockedOrder(string $buildingId, string $orderId): OrdenOperativaEloquentModel
    {
        return OrdenOperativaEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->lockForUpdate()
            ->findOrFail($orderId);
    }

    private function currentAssignment(OrdenOperativaEloquentModel $order, bool $lock): ?AsignacionOrdenOperativaEloquentModel
    {
        $query = AsignacionOrdenOperativaEloquentModel::query()
            ->where('edificio_id', $order->edificio_id)
            ->where('orden_operativa_id', $order->id)
            ->whereNull('fecha_fin');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertActiveResponsibility(OrdenOperativaEloquentModel $order): void
    {
        $assignment = $this->currentAssignment($order, true);
        if ($assignment === null) {
            throw ValidationException::withMessages(['responsable' => 'La orden requiere un responsable vigente antes de avanzar.']);
        }
        $active = $assignment->tipo_responsable === TipoResponsableOrdenOperativa::USUARIO
            ? $this->structure->activeMember($order->edificio_id, (string) $assignment->responsable_user_id, true)
            : $this->suppliers->activeProvider($order->edificio_id, (string) $assignment->responsable_proveedor_id, true);
        if ($active === null || ($assignment->tipo_responsable === TipoResponsableOrdenOperativa::PROVEEDOR
            && $assignment->responsable_proveedor_id !== $order->proveedor_id)) {
            throw ValidationException::withMessages(['responsable' => 'El responsable vigente está inactivo o ya no corresponde a la orden; reasígnela.']);
        }
    }

    /** @return array<string, mixed>|null */
    private function reporterSnapshot(string $buildingId, ?string $residentId, bool $lock): ?array
    {
        if ($residentId === null) {
            return null;
        }
        $snapshot = $this->reporters->activeSnapshot($buildingId, $residentId, $lock);
        if ($snapshot === null) {
            throw ValidationException::withMessages(['reportante_residente_id' => 'El reportante debe ser un residente activo del edificio.']);
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function activeProvider(string $buildingId, string $providerId, bool $lock): array
    {
        $provider = $this->suppliers->activeProvider($buildingId, $providerId, $lock);
        if ($provider === null) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor debe estar activo en el edificio.']);
        }

        return $provider;
    }

    private function registeredContract(string $buildingId, ?string $providerId, ?string $contractId, bool $lock): ?array
    {
        if ($contractId === null) {
            return null;
        }
        if ($providerId === null) {
            throw ValidationException::withMessages(['proveedor_id' => 'Seleccione el proveedor del contrato.']);
        }
        $contract = $this->suppliers->registeredContract($buildingId, $providerId, $contractId, $lock);
        if ($contract === null) {
            throw ValidationException::withMessages(['contrato_id' => 'El contrato debe estar registrado y corresponder al proveedor del edificio.']);
        }

        return $contract;
    }

    private function nextNumber(): string
    {
        $now = CarbonImmutable::now();
        $year = (int) $now->format('Y');
        $table = DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.consecutivos_orden_operativa'
            : 'consecutivos_orden_operativa';
        DB::table($table)->insertOrIgnore([
            'anio' => $year,
            'ultimo_numero' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $counter = DB::table($table)->where('anio', $year)->lockForUpdate()->first();
        $number = ((int) $counter->ultimo_numero) + 1;
        if ($number > 999999) {
            throw ValidationException::withMessages(['numero' => 'El consecutivo anual de operaciones está agotado.']);
        }
        DB::table($table)->where('anio', $year)->update(['ultimo_numero' => $number, 'updated_at' => $now]);

        return sprintf('OPR-%04d-%06d', $year, $number);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function persistenceData(array $data): array
    {
        return [
            'titulo' => trim($data['titulo']),
            'descripcion' => trim($data['descripcion']),
            'prioridad' => $data['prioridad'],
            'fecha_objetivo' => $data['fecha_objetivo'] ?? null,
            'torre_id' => $data['torre_id'] ?? null,
            'piso_id' => $data['piso_id'] ?? null,
            'departamento_id' => $data['departamento_id'] ?? null,
            'parqueadero_id' => $data['parqueadero_id'] ?? null,
            'bodega_id' => $data['bodega_id'] ?? null,
            'ubicacion_detalle' => $this->nullableTrim($data['ubicacion_detalle'] ?? null),
            'reportante_residente_id' => $data['reportante_residente_id'] ?? null,
            'proveedor_id' => $data['proveedor_id'] ?? null,
            'contrato_id' => $data['contrato_id'] ?? null,
        ];
    }

    private function recordEvent(OrdenOperativaEloquentModel $order, string $actorId, TipoEventoOrdenOperativa $type, ?array $detail): void
    {
        BitacoraOrdenOperativaEloquentModel::query()->create([
            'edificio_id' => $order->edificio_id,
            'orden_operativa_id' => $order->id,
            'tipo' => $type,
            'actor_user_id' => $actorId,
            'detalle' => $detail,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function coreSnapshot(OrdenOperativaEloquentModel $order): array
    {
        return [
            'tipo' => $order->tipo?->value,
            'titulo' => $order->titulo,
            'descripcion' => $order->descripcion,
            'prioridad' => $order->prioridad?->value,
            'fechaObjetivo' => $order->fecha_objetivo?->format('Y-m-d'),
            'torreId' => $order->torre_id,
            'pisoId' => $order->piso_id,
            'departamentoId' => $order->departamento_id,
            'parqueaderoId' => $order->parqueadero_id,
            'bodegaId' => $order->bodega_id,
            'ubicacionDetalle' => $order->ubicacion_detalle,
            'reportanteResidenteId' => $order->reportante_residente_id,
            'reportanteSnapshot' => $order->reportante_snapshot,
            'proveedorId' => $order->proveedor_id,
            'contratoId' => $order->contrato_id,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, array{anterior: mixed, nuevo: mixed}> */
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

    /** @return array<string, mixed> */
    private function serialize(OrdenOperativaEloquentModel $order, bool $detail = false, bool $includeReporterContact = false): array
    {
        $result = [
            'id' => $order->id,
            'edificioId' => $order->edificio_id,
            'numero' => $order->numero,
            ...$this->coreSnapshot($order),
            'reportanteSnapshot' => $this->reporterForPresentation($order->reportante_snapshot, $includeReporterContact),
            'estado' => $order->estado->value,
            'responsableActual' => $order->relationLoaded('asignacionActual') && $order->asignacionActual !== null
                ? $this->serializeAssignment($order->asignacionActual)
                : null,
            'puedeEditar' => $order->estado->permiteEditar(),
            'puedeAportar' => $order->estado->permiteAportes(),
            'createdAt' => $order->created_at?->toIso8601String(),
            'updatedAt' => $order->updated_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $result;
        }

        $result['asignaciones'] = $order->asignaciones->map(fn (AsignacionOrdenOperativaEloquentModel $assignment): array => $this->serializeAssignment($assignment))->all();
        $result['evidencias'] = $order->evidencias->map(static fn (EvidenciaOrdenOperativaEloquentModel $evidence): array => [
            'id' => $evidence->id,
            'nombre' => $evidence->nombre_original,
            'mimeType' => $evidence->mime_type,
            'tamanoBytes' => $evidence->tamano_bytes,
            'descripcion' => $evidence->descripcion,
            'createdAt' => $evidence->created_at?->toIso8601String(),
        ])->all();

        return $result;
    }

    /** @return array<string, mixed> */
    private function serializeSummary(OrdenOperativaEloquentModel $order): array
    {
        return [
            'id' => $order->id,
            'edificioId' => $order->edificio_id,
            'numero' => $order->numero,
            'tipo' => $order->tipo->value,
            'titulo' => $order->titulo,
            'prioridad' => $order->prioridad->value,
            'fechaObjetivo' => $order->fecha_objetivo?->format('Y-m-d'),
            'estado' => $order->estado->value,
            'responsableActual' => $order->asignacionActual === null
                ? null
                : $this->serializeAssignment($order->asignacionActual),
            'puedeEditar' => $order->estado->permiteEditar(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAssignment(AsignacionOrdenOperativaEloquentModel $assignment): array
    {
        return [
            'id' => $assignment->id,
            'tipoResponsable' => $assignment->tipo_responsable->value,
            'responsableNombre' => $this->responsibleName($assignment->responsable_snapshot),
            'fechaInicio' => $assignment->fecha_inicio?->toIso8601String(),
            'fechaFin' => $assignment->fecha_fin?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $snapshot @return array<string, mixed>|null */
    private function reporterForPresentation(?array $snapshot, bool $includeContact): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        $result = [
            'nombre' => $snapshot['nombre'] ?? 'Residente no disponible',
            'tipoIdentificacion' => $snapshot['tipoIdentificacion'] ?? null,
            'identificacion' => $snapshot['identificacion'] ?? null,
        ];
        if ($includeContact) {
            $result += [
                'edificioId' => $snapshot['edificioId'] ?? null,
                'residenteId' => $snapshot['residenteId'] ?? null,
                'telefono' => $snapshot['telefono'] ?? null,
                'correo' => $snapshot['correo'] ?? null,
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $snapshot */
    private function responsibleName(array $snapshot): string
    {
        return (string) ($snapshot['nombre'] ?? $snapshot['nombreIdentidad'] ?? 'Responsable no disponible');
    }

    /** @param array<string, string> $assignmentNames @return array<string, mixed>|null */
    private function safeEventDetail(BitacoraOrdenOperativaEloquentModel $entry, array $assignmentNames): ?array
    {
        $detail = $entry->detalle ?? [];

        return match ($entry->tipo) {
            TipoEventoOrdenOperativa::CREACION => ['resumen' => 'Datos iniciales de la orden registrados.'],
            TipoEventoOrdenOperativa::CAMBIO_DATOS => ['cambios' => $this->safeChanges($detail['cambios'] ?? [])],
            TipoEventoOrdenOperativa::CAMBIO_ESTADO => [
                'estadoAnterior' => $detail['estadoAnterior'] ?? null,
                'estadoNuevo' => $detail['estadoNuevo'] ?? null,
            ],
            TipoEventoOrdenOperativa::ASIGNACION, TipoEventoOrdenOperativa::REASIGNACION => [
                'tipoResponsable' => $detail['tipoResponsable'] ?? null,
                'responsableNombre' => $detail['responsableNombre']
                    ?? $assignmentNames[$detail['asignacionNuevaId'] ?? '']
                    ?? 'Responsable no disponible',
            ],
            TipoEventoOrdenOperativa::CANCELACION, TipoEventoOrdenOperativa::REAPERTURA => [
                'estadoAnterior' => $detail['estadoAnterior'] ?? null,
                'estadoNuevo' => $detail['estadoNuevo'] ?? null,
                'motivo' => $detail['motivo'] ?? null,
            ],
            TipoEventoOrdenOperativa::EVIDENCIA => [
                'nombre' => $detail['nombre'] ?? null,
                'mimeType' => $detail['mimeType'] ?? null,
                'tamanoBytes' => $detail['tamanoBytes'] ?? null,
            ],
            TipoEventoOrdenOperativa::ACTUACION_MANUAL => ['descripcion' => $detail['descripcion'] ?? null],
        };
    }

    /** @param array<string, array{anterior: mixed, nuevo: mixed}> $changes @return array<string, array{anterior: mixed, nuevo: mixed}> */
    private function safeChanges(array $changes): array
    {
        $safeValues = ['titulo', 'descripcion', 'prioridad', 'fechaObjetivo', 'ubicacionDetalle'];
        $result = [];
        foreach ($changes as $field => $values) {
            if ($field === 'reportanteSnapshot') {
                continue;
            }
            if (in_array($field, $safeValues, true)) {
                $result[$field] = $values;

                continue;
            }
            $result[$field] = [
                'anterior' => ($values['anterior'] ?? null) === null ? 'Sin configurar' : 'Configurado',
                'nuevo' => ($values['nuevo'] ?? null) === null ? 'Sin configurar' : 'Configurado',
            ];
        }

        return $result;
    }

    private function nextStateChangeTime(OrdenOperativaEloquentModel $order): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $previous = $order->estado_actualizado_at;

        return $previous !== null && $now->lessThanOrEqualTo($previous)
            ? CarbonImmutable::instance($previous)->addSecond()
            : $now;
    }

    private function advanceOrderVersion(OrdenOperativaEloquentModel $order): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $previous = $order->updated_at;
        $order->updated_at = $previous !== null && $now->lessThanOrEqualTo($previous)
            ? CarbonImmutable::instance($previous)->addSecond()
            : $now;
    }

    private function assertEvidenceCapacity(string $orderId, int $incomingBytes): void
    {
        $query = EvidenciaOrdenOperativaEloquentModel::query()->where('orden_operativa_id', $orderId);
        $count = (clone $query)->count();
        $bytes = (int) $query->sum('tamano_bytes');
        if ($count >= self::MAX_EVIDENCE_FILES_PER_ORDER
            || $bytes + $incomingBytes > self::MAX_EVIDENCE_BYTES_PER_ORDER) {
            throw ValidationException::withMessages([
                'archivo' => 'La orden alcanzó el límite de 100 evidencias o 500 MB acumulados.',
            ]);
        }
    }

    private function validateFileContent(UploadedFile $file, string $mimeType, int $size): void
    {
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
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
            $valid = $header === '%PDF-'
                && $tail !== false
                && preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $tail, $matches) === 1;
            if ($valid) {
                $xrefOffset = (int) $matches[1];
                $valid = $xrefOffset >= 0 && $xrefOffset < $size && fseek($handle, $xrefOffset) === 0;
                $xref = $valid ? fread($handle, 2048) : false;
                $valid = $xref !== false
                    && (str_starts_with(ltrim($xref), 'xref') || preg_match('/\/Type\s*\/XRef\b/', $xref) === 1);
            }
            fclose($handle);
            if (! $valid) {
                throw ValidationException::withMessages(['archivo' => 'El PDF no tiene una estructura válida.']);
            }

            return;
        }
        $image = @getimagesize($path);
        $expected = $mimeType === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
        if ($image === false || ($image[2] ?? null) !== $expected) {
            throw ValidationException::withMessages(['archivo' => 'La imagen no tiene una estructura válida.']);
        }
    }

    private function safeFilename(string $original, string $extension): string
    {
        $name = Str::ascii(pathinfo($original, PATHINFO_FILENAME));
        $name = preg_replace('/[^A-Za-z0-9_ -]+/', '_', $name) ?? '';
        $name = trim($name, " .-_\t\n\r\0\x0B");
        $name = Str::limit($name === '' ? 'evidencia' : $name, 180, '');

        return $name.'.'.$extension;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
