<?php

namespace Src\Gobierno\Application\Actions;

use Carbon\CarbonImmutable;
use RuntimeException;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Domain\Exceptions\AutorizacionExportacionRevocada;
use Src\Gobierno\Domain\Exceptions\LimiteFilasExportacionExcedido;
use Src\Gobierno\Infrastructure\Exports\CsvAuditoriaWriter;
use Src\Gobierno\Infrastructure\Exports\ExportacionAuditoriaTemporaryFiles;
use Src\Gobierno\Infrastructure\Exports\PdfAuditoriaWriter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class GenerarExportacionAuditoriaAction
{
    public function __construct(
        private readonly AuditoriaRepositoryInterface $audit,
        private readonly ExportacionAuditoriaRepositoryInterface $exports,
        private readonly CsvAuditoriaWriter $csv,
        private readonly PdfAuditoriaWriter $pdf,
        private readonly ExportacionAuditoriaTemporaryFiles $temporaries,
    ) {}

    public function execute(string $exportId): void
    {
        $claim = $this->exports->claim($exportId);
        if ($claim === null) {
            return;
        }
        $extension = $claim['format'];
        $temp = null;
        $disk = (string) config('gobierno.exports.disk');
        $path = "exports/{$claim['id']}/{$claim['leaseToken']}.{$extension}";
        $uploaded = false;
        $stream = null;
        try {
            $temp = $this->temporaries->create('generation');
            $snapshots = collect($claim['buildings'])->keyBy('id');
            $limit = (int) config("gobierno.exports.{$extension}_max_rows");
            $chunkSize = min((int) config('gobierno.exports.chunk_size'), $limit + 1);
            if ($extension === 'csv') {
                $stream = fopen($temp, 'wb');
                if ($stream === false) {
                    throw new RuntimeException('No fue posible abrir la exportación temporal.');
                }
                $this->csv->header($stream);
            }
            $snapshotResult = $this->audit->withinConsistentExportSnapshot(function () use (
                $claim,
                $chunkSize,
                $extension,
                $limit,
                $snapshots,
                $stream,
            ): array {
                $rows = [];
                $processed = 0;
                $cursor = null;
                do {
                    $chunk = $this->chunkForClaim($claim, $cursor, $chunkSize);
                    $this->assertCurrentScope($claim['buildings'], $chunk['scope']);
                    foreach ($chunk['items'] as $item) {
                        $snapshot = $snapshots->get($item['edificioId']);
                        if ($snapshot === null || ! $this->wasSourceVisible($snapshot['sourcePermissions'], $item['fuente'], $item['tipo'])) {
                            continue;
                        }
                        $item = $this->applySnapshot($item, $snapshot);
                        $processed++;
                        if ($processed > $limit) {
                            throw new LimiteFilasExportacionExcedido();
                        }
                        if ($extension === 'csv') {
                            $this->csv->row($stream, $item);
                        } else {
                            $rows[] = $item;
                        }
                    }
                    $cursor = $chunk['nextCursor'];
                } while ($chunk['hasMore']);

                return ['rows' => $rows, 'processed' => $processed];
            });
            $rows = $snapshotResult['rows'];
            $processed = $snapshotResult['processed'];
            if (is_resource($stream)) {
                fclose($stream);
                $stream = null;
            }
            $this->renewOrFail($claim, $processed);
            if ($extension === 'pdf') {
                $this->pdf->write($temp, $rows, ['from' => $claim['from'], 'until' => $claim['until'], 'cutoff' => $claim['cutoff']]);
            }
            $size = filesize($temp);
            $sha256 = hash_file('sha256', $temp);
            if ($size === false || $sha256 === false) {
                throw new RuntimeException('No fue posible verificar la exportación temporal.');
            }
            $this->renewOrFail($claim, $processed);
            $this->exports->registerCleanupIntent($claim['id'], $claim['leaseToken'], $disk, $path);
            $upload = fopen($temp, 'rb');
            if ($upload === false) {
                throw new RuntimeException('No fue posible leer la exportación temporal.');
            }
            try {
                if (! Storage::disk($disk)->put($path, $upload, ['visibility' => 'private'])) {
                    throw new RuntimeException('No fue posible almacenar la exportación.');
                }
            } finally {
                fclose($upload);
            }
            $uploaded = true;
            if (Storage::disk($disk)->size($path) !== $size || ! hash_equals($sha256, $this->storageHash($disk, $path))) {
                throw new RuntimeException('La exportación almacenada no coincide con el archivo generado.');
            }
            $this->assertCurrentScope($claim['buildings'], $this->currentScope($claim));
            $this->renewOrFail($claim, $processed);
            $name = 'auditoria-'.CarbonImmutable::now('UTC')->format('Ymd-His').'-'.substr($claim['id'], 0, 8).'.'.$extension;
            $completed = $this->exports->complete($claim['id'], $claim['leaseToken'], [
                'disk' => $disk,
                'path' => $path,
                'name' => $name,
                'mimeType' => $extension === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf',
                'size' => $size,
                'sha256' => $sha256,
            ], $processed);
            if (! $completed) {
                throw new RuntimeException('LeaseExportacionPerdido');
            }
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($uploaded) {
                try {
                    $deleted = ! Storage::disk($disk)->exists($path) || Storage::disk($disk)->delete($path);
                    if ($deleted && ! Storage::disk($disk)->exists($path)) {
                        $this->exports->forgetCleanupIntent($disk, $path);
                    }
                } catch (Throwable) {
                    // El intent durable conserva la ruta para la limpieza programada.
                }
            }
            $this->exports->fail($claim['id'], $claim['leaseToken'], $exception);

            throw $exception;
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /** @param list<string> $permissions */
    private function wasSourceVisible(array $permissions, string $source, string $eventType): bool
    {
        $permission = match ($source) {
            'accesos' => PermisoEdificio::MIEMBROS_VER,
            'operaciones' => PermisoEdificio::OPERACIONES_VER,
            'mantenimiento' => PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER,
            'comunicacion' => PermisoEdificio::COMUNICADOS_VER,
            'areas_comunes' => str_starts_with($eventType, 'reserva_')
                ? PermisoEdificio::RESERVAS_AREAS_COMUNES_VER
                : PermisoEdificio::AREAS_COMUNES_VER,
            default => null,
        };

        return $permission !== null && in_array($permission->value, $permissions, true);
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function applySnapshot(array $item, array $snapshot): array
    {
        $item['edificio'] = $snapshot['name'];
        $item['ocurridoLocal'] = CarbonImmutable::parse($item['ocurridoAt'])
            ->setTimezone($snapshot['timezone'])->format('Y-m-d H:i:s T');
        if (! $snapshot['includeDetails']) {
            $item['actor'] = $item['actorTipo'] === 'sistema' ? 'Sistema' : null;
            $item['recursoReferencia'] = null;
            $item['detalleRestringido'] = true;
        }

        return $item;
    }

    private function storageHash(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('No fue posible verificar la exportación almacenada.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $claim */
    private function renewOrFail(array $claim, int $processed): void
    {
        if (! $this->exports->renewProgress($claim['id'], $claim['leaseToken'], $processed)) {
            throw new RuntimeException('LeaseExportacionPerdido');
        }
    }

    /** @param array<string, mixed> $claim @param array<string, string>|null $cursor @return array<string, mixed> */
    private function chunkForClaim(array $claim, ?array $cursor, int $limit): array
    {
        try {
            return $this->audit->exportChunkForUser($claim['userId'], [
                'edificios' => array_column($claim['buildings'], 'id'),
                'fuente' => $claim['source'],
                'tipo' => $claim['eventType'],
                'desde_utc' => $claim['from'],
                'hasta_utc' => $claim['until'],
                'corte_at' => $claim['cutoff'],
            ], $cursor, $limit);
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                throw new AutorizacionExportacionRevocada();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $claim @return list<array<string, mixed>> */
    private function currentScope(array $claim): array
    {
        try {
            return $this->audit->exportScopeForUser(
                $claim['userId'],
                array_column($claim['buildings'], 'id'),
                $claim['source'],
                $claim['eventType'],
            );
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                throw new AutorizacionExportacionRevocada();
            }

            throw $exception;
        }
    }

    /** @param list<array<string, mixed>> $snapshots @param list<array<string, mixed>> $current */
    private function assertCurrentScope(array $snapshots, array $current): void
    {
        $currentByBuilding = collect($current)->keyBy('id');
        foreach ($snapshots as $snapshot) {
            $building = $currentByBuilding->get($snapshot['id']);
            if ($building === null
                || array_diff($snapshot['sourcePermissions'], $building['permisosFuente']) !== []
                || ($snapshot['includeDetails'] && ! $building['detalleIncluido'])) {
                throw new AutorizacionExportacionRevocada();
            }
        }
    }
}
