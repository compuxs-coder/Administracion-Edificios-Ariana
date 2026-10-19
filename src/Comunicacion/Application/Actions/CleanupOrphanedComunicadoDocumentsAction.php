<?php

namespace Src\Comunicacion\Application\Actions;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Src\Comunicacion\Infrastructure\Models\DocumentoComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\LimpiezaDocumentoComunicacionEloquentModel;

final readonly class CleanupOrphanedComunicadoDocumentsAction
{
    /** @return array{examined: int, deleted: int, errors: int, limitReached: bool} */
    public function execute(int $olderThanHours = 24, int $limit = 500): array
    {
        $olderThanHours = max(1, $olderThanHours);
        $limit = max(1, min($limit, 5000));
        $pending = LimpiezaDocumentoComunicacionEloquentModel::query()
            ->where('created_at', '<=', now()->subHours($olderThanHours))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get();
        $examined = 0;
        $deleted = 0;
        $errors = 0;
        $limitReached = $pending->count() === $limit
            && LimpiezaDocumentoComunicacionEloquentModel::query()
                ->where('created_at', '<=', now()->subHours($olderThanHours))->count() > $limit;

        foreach ($pending as $entry) {
            $examined++;
            if (! str_starts_with($entry->ruta_privada, 'comunicacion/') || str_contains($entry->ruta_privada, '..')) {
                $errors++;
                Log::warning('Se descartó una ruta de limpieza fuera del espacio de Comunicación.', [
                    'disco' => $entry->disco,
                    'ruta' => $entry->ruta_privada,
                ]);
                $entry->delete();

                continue;
            }
            try {
                if (DocumentoComunicadoEloquentModel::query()->where('ruta_privada', $entry->ruta_privada)->exists()) {
                    $entry->delete();

                    continue;
                }
                $disk = $this->privateDisk($entry->disco);
                if ($disk->exists($entry->ruta_privada) && ! $disk->delete($entry->ruta_privada)) {
                    throw new RuntimeException('El adaptador no confirmó la eliminación del archivo.');
                }
                $entry->delete();
                $deleted++;
            } catch (\Throwable $exception) {
                $errors++;
                Log::warning('No se pudo limpiar un documento huérfano de Comunicación.', [
                    'disco' => $entry->disco,
                    'ruta' => $entry->ruta_privada,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return compact('examined', 'deleted', 'errors', 'limitReached');
    }

    private function privateDisk(string $name): FilesystemAdapter
    {
        $configuration = config("filesystems.disks.{$name}");
        if ($name === '' || mb_strlen($name) > 100 || ! is_array($configuration)
            || ($configuration['visibility'] ?? null) !== 'private' || ($configuration['serve'] ?? false) === true) {
            throw new RuntimeException('El disco de documentos de Comunicación debe ser privado y no servido.');
        }

        return Storage::disk($name);
    }
}
