<?php

namespace Src\Finanzas\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Contracts\ReciboPagoRepositoryInterface;
use Src\Finanzas\Domain\Enums\EstadoPago;
use Src\Finanzas\Infrastructure\Models\EvidenciaPagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ReciboPagoEloquentModel;

final class EloquentReciboPagoRepository implements ReciboPagoRepositoryInterface
{
    public function get(string $userId, string $edificioId, string $pagoId): array
    {
        $this->authorizedEdificio($userId, $edificioId);
        $recibo = ReciboPagoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('pago_id', $pagoId)
            ->with(['pago', 'emitidoPor', 'anuladoPor', 'pago.evidencias.subidoPor'])
            ->firstOrFail();

        return $this->serialize($recibo);
    }

    public function storeEvidence(string $userId, string $edificioId, string $pagoId, array $data): array
    {
        /** @var UploadedFile $archivo */
        $archivo = $data['archivo'];
        $path = null;

        try {
            return DB::transaction(function () use ($userId, $edificioId, $pagoId, $data, $archivo, &$path): array {
                $this->authorizedEdificio($userId, $edificioId, true);
                $pago = PagoEloquentModel::query()->where('edificio_id', $edificioId)->lockForUpdate()->findOrFail($pagoId);
                if ($pago->estado !== EstadoPago::REGISTRADO) {
                    throw ValidationException::withMessages(['pago' => 'No se pueden adjuntar evidencias a un pago anulado.']);
                }
                if (! ReciboPagoEloquentModel::query()->where('pago_id', $pago->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['pago' => 'El pago no tiene un recibo emitido.']);
                }

                $evidencia = new EvidenciaPagoEloquentModel();
                $evidencia->id = (string) Str::uuid();
                $mimeType = (string) $archivo->getMimeType();
                $size = (int) $archivo->getSize();
                $extension = match ($mimeType) {
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    default => throw ValidationException::withMessages(['archivo' => 'El archivo debe ser PDF, JPG o PNG.']),
                };
                $this->validateFileContent($archivo, $mimeType, $size);
                $hash = hash_file('sha256', $archivo->getRealPath());
                if ($hash === false) {
                    throw new RuntimeException('No fue posible verificar la evidencia de pago.');
                }
                $path = Storage::disk('evidence')->putFileAs($edificioId.'/'.$pago->id, $archivo, $evidencia->id.'.'.$extension);
                if ($path === false) {
                    throw new RuntimeException('No fue posible almacenar la evidencia de pago.');
                }
                if (Storage::disk('evidence')->size($path) !== $size || ! hash_equals($hash, (string) hash_file('sha256', Storage::disk('evidence')->path($path)))) {
                    throw new RuntimeException('La evidencia almacenada no coincide con el archivo recibido.');
                }
                $evidencia->edificio_id = $edificioId;
                $evidencia->departamento_id = $pago->departamento_id;
                $evidencia->pago_id = $pago->id;
                $evidencia->fill([
                    'nombre_original' => $this->safeFilename($archivo->getClientOriginalName(), $extension),
                    'mime_type' => $mimeType,
                    'tamano_bytes' => $size,
                    'sha256' => $hash,
                    'ruta_privada' => $path,
                    'descripcion' => $data['descripcion'],
                    'subido_por' => $userId,
                ])->save();

                return ['id' => $evidencia->id];
            });
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('evidence')->delete($path);
            }

            throw $exception;
        }
    }

    public function evidenceDownload(string $userId, string $edificioId, string $pagoId, string $evidenciaId): array
    {
        $this->authorizedEdificio($userId, $edificioId);
        $evidencia = EvidenciaPagoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('pago_id', $pagoId)
            ->findOrFail($evidenciaId);

        abort_unless(Storage::disk('evidence')->exists($evidencia->ruta_privada), 404);
        $path = Storage::disk('evidence')->path($evidencia->ruta_privada);
        abort_unless(Storage::disk('evidence')->size($evidencia->ruta_privada) === (int) $evidencia->tamano_bytes, 409, 'La evidencia no supera la verificación de integridad.');
        abort_unless(hash_equals($evidencia->sha256, (string) hash_file('sha256', $path)), 409, 'La evidencia no supera la verificación de integridad.');

        return ['path' => $evidencia->ruta_privada, 'nombre' => $evidencia->nombre_original, 'mimeType' => $evidencia->mime_type];
    }

    private function authorizedEdificio(string $userId, string $edificioId, bool $lock = false): EdificioEloquentModel
    {
        $query = EdificioEloquentModel::query()->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    private function validateFileContent(UploadedFile $archivo, string $mimeType, int $size): void
    {
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no puede superar 10 MB.']);
        }
        $path = $archivo->getRealPath();
        if ($mimeType === 'application/pdf') {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw ValidationException::withMessages(['archivo' => 'No fue posible leer el PDF.']);
            }
            $header = fread($handle, 5);
            fseek($handle, -min($size, 2048), SEEK_END);
            $tail = stream_get_contents($handle);
            fclose($handle);
            if ($header !== '%PDF-' || $tail === false || ! str_contains($tail, '%%EOF')) {
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
        $name = Str::limit($name === '' ? 'comprobante' : $name, 180, '');

        return $name.'.'.$extension;
    }

    /** @return array<string, mixed> */
    private function serialize(ReciboPagoEloquentModel $recibo): array
    {
        return [
            'id' => $recibo->id,
            'numero' => $recibo->numero,
            'estado' => $recibo->estado->value,
            'fechaEmision' => $recibo->created_at?->toISOString(),
            'fechaPago' => $recibo->fecha_pago_snapshot->format('Y-m-d'),
            'edificio' => ['id' => $recibo->edificio_id, 'nombre' => $recibo->edificio_nombre_snapshot],
            'departamento' => ['id' => $recibo->departamento_id, 'codigo' => $recibo->departamento_codigo_snapshot, 'nombre' => $recibo->departamento_nombre_snapshot],
            'pago' => ['id' => $recibo->pago_id, 'numero' => $recibo->pago?->numero, 'estado' => $recibo->pago?->estado->value],
            'titulares' => $recibo->titulares_snapshot ?? [],
            'valorRecibido' => $recibo->monto_recibido_snapshot,
            'formaPago' => $recibo->forma_pago_snapshot,
            'referencia' => $recibo->referencia_snapshot,
            'aplicaciones' => $recibo->aplicaciones_snapshot ?? [],
            'emitidoPor' => $recibo->emitidoPor?->name,
            'anuladoAt' => $recibo->anulado_at?->toISOString(),
            'anuladoPor' => $recibo->anuladoPor?->name,
            'motivoAnulacion' => $recibo->motivo_anulacion,
            'evidencias' => $recibo->pago?->evidencias->map(static fn (EvidenciaPagoEloquentModel $evidencia): array => [
                'id' => $evidencia->id,
                'nombre' => $evidencia->nombre_original,
                'mimeType' => $evidencia->mime_type,
                'tamanoBytes' => $evidencia->tamano_bytes,
                'descripcion' => $evidencia->descripcion,
                'subidoAt' => $evidencia->created_at?->toISOString(),
                'subidoPor' => $evidencia->subidoPor?->name,
            ])->all() ?? [],
        ];
    }
}
