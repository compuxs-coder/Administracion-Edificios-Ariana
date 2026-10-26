<?php

namespace Src\Gobierno\Infrastructure\Exports;

use Illuminate\Support\Facades\File;
use FilesystemIterator;
use RuntimeException;
use Throwable;

final class ExportacionAuditoriaTemporaryFiles
{
    public function create(string $purpose): string
    {
        $directory = $this->directory();
        $path = tempnam($directory, "{$purpose}-");
        $realParent = is_string($path) ? realpath(dirname($path)) : false;
        if ($path === false || $realParent !== $directory || ! chmod($path, 0600)) {
            if (is_string($path)) {
                @unlink($path);
            }

            throw new RuntimeException('No fue posible preparar un archivo temporal privado.');
        }

        return $path;
    }

    /** @return array{deleted: int, errors: int} */
    public function cleanup(int $limit): array
    {
        $deleted = 0;
        $errors = 0;
        $cutoff = now('UTC')->subSeconds((int) config('gobierno.exports.temp_max_age_seconds'))->getTimestamp();

        try {
            $files = new FilesystemIterator($this->directory(), FilesystemIterator::SKIP_DOTS);
        } catch (Throwable) {
            return ['deleted' => 0, 'errors' => 1];
        }

        $examined = 0;
        $examinedLimit = min(50000, $limit * 10);
        foreach ($files as $file) {
            if ($examined >= $examinedLimit || $deleted + $errors >= $limit) {
                break;
            }
            $examined++;
            if (! $file->isFile() || $file->getMTime() > $cutoff) {
                continue;
            }

            try {
                if (! File::delete($file->getPathname())) {
                    throw new RuntimeException('No fue posible eliminar un archivo temporal de exportación.');
                }
                $deleted++;
            } catch (Throwable) {
                $errors++;
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    private function directory(): string
    {
        $privateRoot = storage_path('app/private');
        $directory = $privateRoot.DIRECTORY_SEPARATOR.'audit-export-temporaries';
        File::ensureDirectoryExists($directory, 0700, true);
        @chmod($directory, 0700);

        $realRoot = realpath($privateRoot);
        $realDirectory = realpath($directory);
        if ($realRoot === false || $realDirectory === false
            || ($realDirectory !== $realRoot && ! str_starts_with($realDirectory, $realRoot.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('La ruta temporal de exportaciones no es privada.');
        }

        return $realDirectory;
    }
}
