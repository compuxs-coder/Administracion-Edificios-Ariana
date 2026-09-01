<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = $pgsql ? "{$schema}.evidencias_pago" : 'evidencias_pago';

        foreach (DB::table($table)->orderBy('id')->get() as $evidencia) {
            if (! Storage::disk('evidence')->exists($evidencia->ruta_privada)) {
                $legacyPath = 'evidencias_pago/'.$evidencia->ruta_privada;
                if (! Storage::disk('local')->exists($legacyPath)) {
                    throw new RuntimeException("No se encontró el archivo privado de la evidencia {$evidencia->id}.");
                }
                Storage::disk('evidence')->put($evidencia->ruta_privada, Storage::disk('local')->get($legacyPath));
            }
            $path = Storage::disk('evidence')->path($evidencia->ruta_privada);
            if (Storage::disk('evidence')->size($evidencia->ruta_privada) !== (int) $evidencia->tamano_bytes
                || ! hash_equals($evidencia->sha256, (string) hash_file('sha256', $path))) {
                throw new RuntimeException("La evidencia {$evidencia->id} no supera la verificación de integridad.");
            }
            foreach ([$evidencia->ruta_privada, 'evidencias_pago/'.$evidencia->ruta_privada] as $legacyPath) {
                if (Storage::disk('local')->exists($legacyPath) && ! Storage::disk('local')->delete($legacyPath)) {
                    throw new RuntimeException("No se pudo retirar la copia heredada de la evidencia {$evidencia->id}.");
                }
            }
        }
    }

    public function down(): void
    {
        // Evidence remains on the dedicated private disk after rollback.
    }
};
