<?php

namespace Src\Gobierno\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Domain\Enums\EstadoExportacionAuditoria;
use Src\Gobierno\Domain\Enums\TipoEventoExportacionAuditoria;
use Src\Gobierno\Domain\Exceptions\AutorizacionExportacionRevocada;
use Src\Gobierno\Domain\Exceptions\LimiteFilasExportacionExcedido;
use Src\Gobierno\Infrastructure\Models\BitacoraExportacionAuditoriaEloquentModel;
use Src\Gobierno\Infrastructure\Models\ExportacionAuditoriaEloquentModel;
use Src\Gobierno\Infrastructure\Models\LimpiezaArchivoExportacionEloquentModel;
use Src\Gobierno\Infrastructure\Exports\ExportacionAuditoriaTemporaryFiles;
use Throwable;

final class EloquentExportacionAuditoriaRepository implements ExportacionAuditoriaRepositoryInterface
{
    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly ExportacionAuditoriaTemporaryFiles $temporaries,
    ) {}

    public function request(string $userId, array $data, array $scope): array
    {
        $data['idempotency_key'] = strtolower((string) $data['idempotency_key']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($userId, $data, $scope): array {
                    UserEloquentModel::query()->lockForUpdate()->findOrFail($userId);
                    $existing = $this->findRequest($userId, $data['idempotency_key']);
                    if ($existing !== null) {
                        $this->assertSameRequest($existing, $data);

                        return ['id' => $existing->id, 'created' => false];
                    }
                    $active = ExportacionAuditoriaEloquentModel::query()->where('solicitante_user_id', $userId)
                        ->whereIn('estado', [EstadoExportacionAuditoria::PENDIENTE, EstadoExportacionAuditoria::PROCESANDO])
                        ->count();
                    if ($active >= (int) config('gobierno.exports.max_active_per_user')) {
                        throw ValidationException::withMessages(['exportacion' => 'Alcanzó el máximo de exportaciones activas.']);
                    }
                    $daily = ExportacionAuditoriaEloquentModel::query()->where('solicitante_user_id', $userId)
                        ->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
                        ->count();
                    if ($daily >= (int) config('gobierno.exports.max_daily_per_user')) {
                        throw ValidationException::withMessages(['exportacion' => 'Alcanzó el máximo diario de exportaciones.']);
                    }

                    $now = CarbonImmutable::now('UTC');
                    $export = ExportacionAuditoriaEloquentModel::query()->create([
                        'solicitante_user_id' => $userId,
                        'idempotency_key' => $data['idempotency_key'],
                        'formato' => $data['formato'],
                        'estado' => EstadoExportacionAuditoria::PENDIENTE,
                        'fuente' => $data['fuente'] ?? null,
                        'tipo' => $data['tipo'] ?? null,
                        'desde_utc' => $data['desde_utc'],
                        'hasta_utc' => $data['hasta_utc'],
                        'corte_at' => $data['corte_at'],
                        'disponible_at' => $now,
                    ]);
                    foreach ($scope as $building) {
                        DB::table($this->table('exportacion_auditoria_edificios'))->insert([
                            'exportacion_id' => $export->id,
                            'edificio_id' => $building['id'],
                            'nombre_snapshot' => $building['nombre'],
                            'timezone_snapshot' => $building['timezone'],
                            'permisos_fuente' => json_encode($building['permisosFuente'], JSON_THROW_ON_ERROR),
                            'detalle_incluido' => $building['detalleIncluido'],
                            'created_at' => $now,
                        ]);
                    }
                    $this->recordEvent($export->id, TipoEventoExportacionAuditoria::SOLICITADA, 'usuario', $userId);

                    return ['id' => $export->id, 'created' => true];
                });
            } catch (UniqueConstraintViolationException $exception) {
                $existing = $this->findRequest($userId, $data['idempotency_key']);
                if ($existing === null) {
                    throw $exception;
                }
                $this->assertSameRequest($existing, $data);

                return ['id' => $existing->id, 'created' => false];
            } catch (QueryException $exception) {
                if (DB::connection()->getDriverName() !== 'sqlite'
                    || ! str_contains(strtolower($exception->getMessage()), 'database is locked')
                    || $attempt === 2) {
                    throw $exception;
                }
                usleep(50000 * ($attempt + 1));
            }
        }

        throw new RuntimeException('No fue posible registrar la solicitud idempotente.');
    }

    public function claim(string $exportId): ?array
    {
        return DB::transaction(function () use ($exportId): ?array {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->with('edificios')->find($exportId);
            if ($export === null || in_array($export->estado, [EstadoExportacionAuditoria::COMPLETADA, EstadoExportacionAuditoria::FALLIDA, EstadoExportacionAuditoria::EXPIRADA], true)) {
                return null;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($export->estado === EstadoExportacionAuditoria::PENDIENTE && $export->disponible_at?->isAfter($now)) {
                return null;
            }
            if ($export->estado === EstadoExportacionAuditoria::PROCESANDO && $export->lease_until?->isAfter($now)) {
                return null;
            }
            $recovered = $export->estado === EstadoExportacionAuditoria::PROCESANDO;
            if ($recovered && (int) $export->intentos >= (int) config('gobierno.exports.max_attempts')) {
                $this->markTerminalFailure($export, 'LeaseExpirado', $now);

                return null;
            }
            $token = (string) Str::uuid();
            $export->fill([
                'estado' => EstadoExportacionAuditoria::PROCESANDO,
                'intentos' => (int) $export->intentos + 1,
                'lease_token' => $token,
                'lease_until' => $now->addSeconds((int) config('gobierno.exports.lease_seconds')),
                'iniciada_at' => $export->iniciada_at ?? $now,
                'error_codigo' => null,
            ])->save();
            $this->recordEvent(
                $export->id,
                $recovered ? TipoEventoExportacionAuditoria::LEASE_RECUPERADO : TipoEventoExportacionAuditoria::PROCESAMIENTO_INICIADO,
                'sistema',
                null,
                (int) $export->intentos,
            );

            return [
                'id' => $export->id,
                'userId' => $export->solicitante_user_id,
                'leaseToken' => $token,
                'attempt' => (int) $export->intentos,
                'format' => $export->formato->value,
                'source' => $export->fuente,
                'eventType' => $export->tipo,
                'from' => $export->desde_utc->utc()->toIso8601ZuluString(),
                'until' => $export->hasta_utc->utc()->toIso8601ZuluString(),
                'cutoff' => $export->corte_at->utc()->toIso8601ZuluString(),
                'buildings' => $export->edificios->map(static fn ($building): array => [
                    'id' => $building->edificio_id,
                    'name' => $building->nombre_snapshot,
                    'timezone' => $building->timezone_snapshot,
                    'sourcePermissions' => $building->permisos_fuente,
                    'includeDetails' => $building->detalle_incluido,
                ])->all(),
            ];
        });
    }

    public function renewProgress(string $exportId, string $leaseToken, int $processed): bool
    {
        return DB::transaction(function () use ($exportId, $leaseToken, $processed): bool {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->findOrFail($exportId);
            if (! $this->holdsLease($export, $leaseToken)) {
                return false;
            }
            $export->fill([
                'eventos_procesados' => $processed,
                'lease_until' => CarbonImmutable::now('UTC')->addSeconds((int) config('gobierno.exports.lease_seconds')),
            ])->save();

            return true;
        });
    }

    public function complete(string $exportId, string $leaseToken, array $file, int $total): bool
    {
        return DB::transaction(function () use ($exportId, $leaseToken, $file, $total): bool {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->with('edificios')->findOrFail($exportId);
            if (! $this->holdsLease($export, $leaseToken)) {
                return false;
            }
            $this->lockScopeAuthorization($export->solicitante_user_id, $export->edificios->pluck('edificio_id')->all());
            if (! $this->currentScopeSatisfiesSnapshot($this->access->permissionMap($export->solicitante_user_id), $export)) {
                throw new AutorizacionExportacionRevocada();
            }
            $now = CarbonImmutable::now('UTC');
            $export->fill([
                'estado' => EstadoExportacionAuditoria::COMPLETADA,
                'lease_token' => null,
                'lease_until' => null,
                'total_eventos' => $total,
                'eventos_procesados' => $total,
                'disco' => $file['disk'],
                'ruta_privada' => $file['path'],
                'nombre_archivo' => $file['name'],
                'mime_type' => $file['mimeType'],
                'tamano_bytes' => $file['size'],
                'sha256' => $file['sha256'],
                'error_codigo' => null,
                'completada_at' => $now,
                'expires_at' => $now->addHours((int) config('gobierno.exports.ttl_hours')),
            ])->save();
            LimpiezaArchivoExportacionEloquentModel::query()
                ->where('disco', $file['disk'])->where('ruta_privada', $file['path'])->delete();
            $this->recordEvent($export->id, TipoEventoExportacionAuditoria::COMPLETADA, 'sistema', null, (int) $export->intentos);

            return true;
        });
    }

    public function fail(string $exportId, string $leaseToken, Throwable $exception): void
    {
        DB::transaction(function () use ($exportId, $leaseToken, $exception): void {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->find($exportId);
            if ($export === null || ! $this->holdsLease($export, $leaseToken)) {
                return;
            }
            $now = CarbonImmutable::now('UTC');
            $code = match (true) {
                $exception instanceof LimiteFilasExportacionExcedido => 'LimiteFilasExportacionExcedido',
                $exception instanceof AutorizacionExportacionRevocada => 'AutorizacionExportacionRevocada',
                default => 'ErrorGeneracion',
            };
            if ($exception instanceof LimiteFilasExportacionExcedido
                || $exception instanceof AutorizacionExportacionRevocada
                || (int) $export->intentos >= (int) config('gobierno.exports.max_attempts')) {
                $this->markTerminalFailure($export, $code, $now);

                return;
            }
            $backoff = match ((int) $export->intentos) {
                1 => 60,
                2 => 300,
                default => 900,
            };
            $export->fill([
                'estado' => EstadoExportacionAuditoria::PENDIENTE,
                'lease_token' => null,
                'lease_until' => null,
                'disponible_at' => $now->addSeconds($backoff),
                'error_codigo' => $code,
            ])->save();
        });
    }

    public function dueIds(int $limit = 100): array
    {
        $now = CarbonImmutable::now('UTC');

        return ExportacionAuditoriaEloquentModel::query()
            ->where(static fn ($query) => $query
                ->where(static fn ($pending) => $pending->where('estado', EstadoExportacionAuditoria::PENDIENTE)->where('disponible_at', '<=', $now))
                ->orWhere(static fn ($processing) => $processing->where('estado', EstadoExportacionAuditoria::PROCESANDO)->where('lease_until', '<=', $now)))
            ->orderBy('disponible_at')->orderBy('id')->limit(max(1, min($limit, 500)))->pluck('id')->all();
    }

    public function recentForUser(string $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $permissionMap = $this->access->permissionMap($userId);
        $eligibleBuildingIds = array_keys(array_filter(
            $permissionMap,
            static fn (array $permissions): bool => in_array(PermisoEdificio::GOBIERNO_VER->value, $permissions, true)
                && in_array(PermisoEdificio::GOBIERNO_EXPORTAR->value, $permissions, true),
        ));
        if ($eligibleBuildingIds === []) {
            return [];
        }

        $authorized = ExportacionAuditoriaEloquentModel::query()->where('solicitante_user_id', $userId)
            ->whereHas('edificios')
            ->whereDoesntHave('edificios', static fn ($query) => $query->whereNotIn('edificio_id', $eligibleBuildingIds))
            ->with('edificios')->orderByDesc('created_at')->orderByDesc('id')
            ->limit((int) config('gobierno.exports.recent_scan_limit'))->get()
            ->filter(fn (ExportacionAuditoriaEloquentModel $export): bool => $this->currentScopeSatisfiesSnapshot($permissionMap, $export))
            ->take($limit)->values();

        return $authorized->map(static fn (ExportacionAuditoriaEloquentModel $export): array => [
                'id' => $export->id,
                'format' => $export->formato->value,
                'status' => $export->estado->value,
                'source' => $export->fuente,
                'eventType' => $export->tipo,
                'from' => $export->desde_utc->utc()->toIso8601ZuluString(),
                'until' => $export->hasta_utc->utc()->toIso8601ZuluString(),
                'processed' => (int) $export->eventos_procesados,
                'total' => $export->total_eventos,
                'errorCode' => $export->error_codigo,
                'expiresAt' => $export->expires_at?->utc()->toIso8601ZuluString(),
                'createdAt' => $export->created_at?->utc()->toIso8601ZuluString(),
                'buildings' => $export->edificios->pluck('nombre_snapshot')->all(),
                'downloadable' => $export->estado === EstadoExportacionAuditoria::COMPLETADA
                    && $export->archivo_eliminado_at === null
                    && $export->expires_at?->isFuture(),
        ])->all();
    }

    public function downloadForUser(string $userId, string $exportId): array
    {
        $export = ExportacionAuditoriaEloquentModel::query()->where('solicitante_user_id', $userId)
            ->with('edificios')->findOrFail($exportId);
        abort_unless($export->estado === EstadoExportacionAuditoria::COMPLETADA, 404);
        if ($export->expires_at === null || ! $export->expires_at->isFuture()) {
            $this->expire($export->id);
            abort(410, 'La exportación expiró.');
        }
        abort_unless($this->currentScopeSatisfiesSnapshot($this->access->permissionMap($userId), $export), 403);
        $disk = (string) $export->disco;
        $path = (string) $export->ruta_privada;
        $this->assertPrivatePath($disk, $path);
        abort_unless(Storage::disk($disk)->exists($path), 404);
        $verifiedCopy = $this->verifiedCopy($disk, $path, (int) $export->tamano_bytes, (string) $export->sha256);

        try {
            DB::transaction(function () use ($exportId, $userId): void {
                $locked = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->with('edificios')->findOrFail($exportId);
                abort_unless($locked->estado === EstadoExportacionAuditoria::COMPLETADA && $locked->expires_at?->isFuture(), 410, 'La exportación expiró.');
                $this->lockScopeAuthorization($userId, $locked->edificios->pluck('edificio_id')->all());
                abort_unless($this->currentScopeSatisfiesSnapshot($this->access->permissionMap($userId), $locked), 403);
                $this->recordEvent($exportId, TipoEventoExportacionAuditoria::DESCARGADA, 'usuario', $userId);
            });
        } catch (Throwable $exception) {
            @unlink($verifiedCopy);

            throw $exception;
        }

        return ['localPath' => $verifiedCopy, 'name' => (string) $export->nombre_archivo, 'mimeType' => (string) $export->mime_type];
    }

    public function registerCleanupIntent(string $exportId, string $leaseToken, string $disk, string $path): void
    {
        DB::transaction(function () use ($exportId, $leaseToken, $disk, $path): void {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->findOrFail($exportId);
            if (! $this->holdsLease($export, $leaseToken)) {
                throw new RuntimeException('El lease de exportación ya no está vigente.');
            }
            $this->assertPrivatePath($disk, $path);
            LimpiezaArchivoExportacionEloquentModel::query()->firstOrCreate([
                'disco' => $disk,
                'ruta_privada' => $path,
            ], [
                'exportacion_id' => $exportId,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        });
    }

    public function forgetCleanupIntent(string $disk, string $path): void
    {
        LimpiezaArchivoExportacionEloquentModel::query()->where('disco', $disk)->where('ruta_privada', $path)->delete();
    }

    public function cleanupExpired(int $limit = 500): array
    {
        $limit = max(1, min($limit, 5000));
        $now = CarbonImmutable::now('UTC');
        $expired = 0;
        $deleted = 0;
        $errors = 0;
        $ids = ExportacionAuditoriaEloquentModel::query()->where('estado', EstadoExportacionAuditoria::COMPLETADA)
            ->where('expires_at', '<=', $now)->orderBy('expires_at')->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) {
            $this->expire($id);
            $expired++;
        }
        $intents = LimpiezaArchivoExportacionEloquentModel::query()->with([])->orderBy('created_at')->limit($limit)->get();
        foreach ($intents as $intent) {
            try {
                if (! $this->fenceCleanupIntent($intent)) {
                    continue;
                }
                $this->assertPrivatePath($intent->disco, $intent->ruta_privada);
                if (Storage::disk($intent->disco)->exists($intent->ruta_privada)
                    && ! Storage::disk($intent->disco)->delete($intent->ruta_privada)) {
                    throw new RuntimeException('No fue posible eliminar un archivo de exportación.');
                }
                DB::transaction(function () use ($intent): void {
                    $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->find($intent->exportacion_id);
                    if ($export !== null && $export->ruta_privada === $intent->ruta_privada && $export->archivo_eliminado_at === null) {
                        $export->archivo_eliminado_at = CarbonImmutable::now('UTC');
                        $export->save();
                        $this->recordEvent($export->id, TipoEventoExportacionAuditoria::ARCHIVO_ELIMINADO, 'sistema');
                    }
                    LimpiezaArchivoExportacionEloquentModel::query()->whereKey($intent->id)->delete();
                });
                $deleted++;
            } catch (Throwable) {
                $errors++;
                try {
                    LimpiezaArchivoExportacionEloquentModel::query()->whereKey($intent->id)
                        ->update(['created_at' => CarbonImmutable::now('UTC')]);
                } catch (Throwable) {
                    // La siguiente ejecución volverá a intentar incluso si no fue posible reordenar el intent.
                }
            }
        }
        $temporaryCleanup = $this->temporaries->cleanup($limit);
        $errors += $temporaryCleanup['errors'];

        return ['expired' => $expired, 'deleted' => $deleted, 'errors' => $errors];
    }

    private function expire(string $exportId): void
    {
        DB::transaction(function () use ($exportId): void {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->find($exportId);
            if ($export === null || $export->estado !== EstadoExportacionAuditoria::COMPLETADA || $export->expires_at?->isFuture()) {
                return;
            }
            $export->estado = EstadoExportacionAuditoria::EXPIRADA;
            $export->save();
            $this->recordEvent($export->id, TipoEventoExportacionAuditoria::EXPIRADA, 'sistema');
            if ($export->disco !== null && $export->ruta_privada !== null) {
                LimpiezaArchivoExportacionEloquentModel::query()->firstOrCreate([
                    'disco' => $export->disco,
                    'ruta_privada' => $export->ruta_privada,
                ], [
                    'exportacion_id' => $export->id,
                    'created_at' => CarbonImmutable::now('UTC'),
                ]);
            }
        });
    }

    private function markTerminalFailure(ExportacionAuditoriaEloquentModel $export, string $code, CarbonImmutable $now): void
    {
        $export->fill([
            'estado' => EstadoExportacionAuditoria::FALLIDA,
            'lease_token' => null,
            'lease_until' => null,
            'disponible_at' => $now,
            'error_codigo' => $code,
            'fallida_at' => $now,
        ])->save();
        $this->recordEvent($export->id, TipoEventoExportacionAuditoria::FALLIDA, 'sistema', null, (int) $export->intentos, $code);
    }

    private function fenceCleanupIntent(LimpiezaArchivoExportacionEloquentModel $intent): bool
    {
        return DB::transaction(function () use ($intent): bool {
            $export = ExportacionAuditoriaEloquentModel::query()->lockForUpdate()->find($intent->exportacion_id);
            if ($export === null) {
                return true;
            }
            if ($export->estado === EstadoExportacionAuditoria::COMPLETADA
                && $export->expires_at?->isFuture()
                && $export->ruta_privada === $intent->ruta_privada) {
                LimpiezaArchivoExportacionEloquentModel::query()->whereKey($intent->id)->delete();

                return false;
            }
            if ($export->estado !== EstadoExportacionAuditoria::PROCESANDO) {
                if (in_array($export->estado, [EstadoExportacionAuditoria::PENDIENTE, EstadoExportacionAuditoria::FALLIDA], true)
                    && ! $this->cleanupGraceElapsed($intent)) {
                    return false;
                }

                return true;
            }
            if ($export->lease_until?->isFuture()) {
                return false;
            }
            $currentPath = "exports/{$export->id}/{$export->lease_token}.{$export->formato->value}";
            if ($currentPath !== $intent->ruta_privada) {
                return $this->cleanupGraceElapsed($intent);
            }
            $now = CarbonImmutable::now('UTC');
            if ((int) $export->intentos >= (int) config('gobierno.exports.max_attempts')) {
                $this->markTerminalFailure($export, 'LeaseExpirado', $now);
            } else {
                $export->fill([
                    'estado' => EstadoExportacionAuditoria::PENDIENTE,
                    'lease_token' => null,
                    'lease_until' => null,
                    'disponible_at' => $now,
                    'error_codigo' => 'LeaseExpirado',
                ])->save();
            }

            return false;
        });
    }

    private function cleanupGraceElapsed(LimpiezaArchivoExportacionEloquentModel $intent): bool
    {
        return $intent->created_at?->addSeconds((int) config('gobierno.exports.orphan_grace_seconds'))->isPast() === true;
    }

    private function recordEvent(
        string $exportId,
        TipoEventoExportacionAuditoria $type,
        string $actorType,
        ?string $actorUserId = null,
        ?int $attempt = null,
        ?string $errorCode = null,
    ): void {
        BitacoraExportacionAuditoriaEloquentModel::query()->create([
            'exportacion_id' => $exportId,
            'tipo' => $type,
            'actor_tipo' => $actorType,
            'actor_user_id' => $actorUserId,
            'intento' => $attempt,
            'error_codigo' => $errorCode,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function assertSameRequest(ExportacionAuditoriaEloquentModel $export, array $data): void
    {
        $sameBuildings = $export->edificios->pluck('edificio_id')->sort()->values()->all();
        $requestedBuildings = collect($data['edificios'])->sort()->values()->all();
        $same = $export->formato->value === $data['formato']
            && $export->fuente === ($data['fuente'] ?? null)
            && $export->tipo === ($data['tipo'] ?? null)
            && $export->desde_utc->equalTo($data['desde_utc'])
            && $export->hasta_utc->equalTo($data['hasta_utc'])
            && $sameBuildings === $requestedBuildings;
        if (! $same) {
            throw ValidationException::withMessages(['idempotency_key' => 'La clave de idempotencia ya fue usada con otra solicitud.']);
        }
    }

    private function assertPrivatePath(string $disk, string $path): void
    {
        $configured = (string) config('gobierno.exports.disk');
        $diskConfig = config("filesystems.disks.{$disk}");
        if ($disk !== $configured || ! is_array($diskConfig) || ($diskConfig['visibility'] ?? null) !== 'private'
            || ($diskConfig['serve'] ?? false) !== false
            || ($diskConfig['throw'] ?? false) !== true
            || preg_match('/^exports\/[0-9a-f-]{36}\/[0-9a-f-]{36}\.(csv|pdf)$/i', $path) !== 1
            || str_contains($path, '..')) {
            throw new RuntimeException('Configuración insegura de almacenamiento de exportaciones.');
        }
    }

    private function verifiedCopy(string $disk, string $path, int $expectedSize, string $expectedHash): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('No fue posible verificar el archivo exportado.');
        }
        $copy = $this->temporaries->create('download');
        $target = fopen($copy, 'wb');
        if ($target === false) {
            fclose($stream);
            @unlink($copy);
            throw new RuntimeException('No fue posible preparar la descarga verificada.');
        }
        $failure = null;
        try {
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false || fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('No fue posible preparar la descarga verificada.');
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
            $actualHash = hash_final($hash);
            if ($size !== $expectedSize || ! hash_equals($expectedHash, $actualHash)) {
                abort(409, 'La exportación no supera la verificación de integridad.');
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            fclose($stream);
            fclose($target);
        }
        if ($failure !== null) {
            @unlink($copy);

            throw $failure;
        }

        return $copy;
    }

    /** @param array<string, list<string>> $permissionMap */
    private function currentScopeSatisfiesSnapshot(array $permissionMap, ExportacionAuditoriaEloquentModel $export): bool
    {
        foreach ($export->edificios as $snapshot) {
            $permissions = $permissionMap[$snapshot->edificio_id] ?? [];
            if (! in_array(PermisoEdificio::GOBIERNO_VER->value, $permissions, true)
                || ! in_array(PermisoEdificio::GOBIERNO_EXPORTAR->value, $permissions, true)
                || array_diff($snapshot->permisos_fuente, $permissions) !== []
                || ($snapshot->detalle_incluido && ! in_array(PermisoEdificio::GOBIERNO_DETALLES_VER->value, $permissions, true))) {
                return false;
            }
        }

        return true;
    }

    private function findRequest(string $userId, string $idempotencyKey): ?ExportacionAuditoriaEloquentModel
    {
        return ExportacionAuditoriaEloquentModel::query()
            ->where('solicitante_user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->with('edificios')
            ->first();
    }

    private function holdsLease(ExportacionAuditoriaEloquentModel $export, string $leaseToken): bool
    {
        return $export->estado === EstadoExportacionAuditoria::PROCESANDO
            && hash_equals((string) $export->lease_token, $leaseToken)
            && $export->lease_until?->isFuture() === true;
    }

    /** @param list<string> $buildingIds */
    private function lockScopeAuthorization(string $userId, array $buildingIds): void
    {
        $buildingIds = array_values(array_unique($buildingIds));
        sort($buildingIds);
        DB::table($this->table('edificios'))
            ->whereIn('id', $buildingIds)->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table($this->table('edificio_usuario'))
            ->where('user_id', $userId)->whereIn('edificio_id', $buildingIds)
            ->orderBy('edificio_id')->lockForUpdate()->get(['edificio_id']);
        $roles = DB::table($this->table('edificio_usuario_roles'))
            ->where('user_id', $userId)->whereIn('edificio_id', $buildingIds)
            ->orderBy('edificio_id')->orderBy('rol_codigo')->lockForUpdate()
            ->get(['edificio_id', 'rol_codigo'])->pluck('rol_codigo')->unique()->sort()->values()->all();
        if ($roles !== []) {
            DB::table($this->table('rol_permisos'))->whereIn('rol_codigo', $roles)
                ->orderBy('rol_codigo')->orderBy('permiso_codigo')->lockForUpdate()->get(['rol_codigo']);
        }
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
}
