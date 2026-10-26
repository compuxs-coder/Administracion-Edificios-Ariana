<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $users = $pgsql ? 'public.users' : 'users';
        $exports = $table('exportaciones_auditoria');
        $buildings = $table('edificios');

        Schema::create($exports, static function (Blueprint $blueprint) use ($users): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('solicitante_user_id');
            $blueprint->uuid('idempotency_key');
            $blueprint->enum('formato', ['csv', 'pdf']);
            $blueprint->enum('estado', ['pendiente', 'procesando', 'completada', 'fallida', 'expirada'])->default('pendiente');
            $blueprint->string('fuente', 40)->nullable();
            $blueprint->string('tipo', 80)->nullable();
            $blueprint->timestampTz('desde_utc', 6);
            $blueprint->timestampTz('hasta_utc', 6);
            $blueprint->timestampTz('corte_at', 6);
            $blueprint->unsignedSmallInteger('intentos')->default(0);
            $blueprint->uuid('lease_token')->nullable();
            $blueprint->timestampTz('lease_until', 6)->nullable();
            $blueprint->timestampTz('disponible_at', 6)->useCurrent();
            $blueprint->unsignedInteger('total_eventos')->nullable();
            $blueprint->unsignedInteger('eventos_procesados')->default(0);
            $blueprint->string('disco', 100)->nullable();
            $blueprint->string('ruta_privada', 500)->nullable();
            $blueprint->string('nombre_archivo', 220)->nullable();
            $blueprint->string('mime_type', 80)->nullable();
            $blueprint->unsignedBigInteger('tamano_bytes')->nullable();
            $blueprint->char('sha256', 64)->nullable();
            $blueprint->string('error_codigo', 100)->nullable();
            $blueprint->timestampTz('iniciada_at', 6)->nullable();
            $blueprint->timestampTz('completada_at', 6)->nullable();
            $blueprint->timestampTz('fallida_at', 6)->nullable();
            $blueprint->timestampTz('expires_at', 6)->nullable();
            $blueprint->timestampTz('archivo_eliminado_at', 6)->nullable();
            $blueprint->timestampsTz(6);

            $blueprint->unique(['solicitante_user_id', 'idempotency_key'], 'exportaciones_auditoria_idempotencia_uq');
            $blueprint->unique('ruta_privada', 'exportaciones_auditoria_ruta_uq');
            $blueprint->index(['estado', 'disponible_at', 'lease_until'], 'exportaciones_auditoria_cola_idx');
            $blueprint->index(['solicitante_user_id', 'created_at'], 'exportaciones_auditoria_usuario_idx');
            $blueprint->foreign('solicitante_user_id', 'exportaciones_auditoria_solicitante_fk')->references('id')->on($users)->restrictOnDelete();
        });

        Schema::create($table('exportacion_auditoria_edificios'), static function (Blueprint $blueprint) use ($exports, $buildings): void {
            $blueprint->uuid('exportacion_id');
            $blueprint->uuid('edificio_id');
            $blueprint->string('nombre_snapshot', 180);
            $blueprint->string('timezone_snapshot', 64);
            $blueprint->json('permisos_fuente');
            $blueprint->boolean('detalle_incluido')->default(false);
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->primary(['exportacion_id', 'edificio_id'], 'exportacion_auditoria_edificios_pk');
            $blueprint->foreign('exportacion_id', 'exportacion_auditoria_edificios_exportacion_fk')->references('id')->on($exports)->restrictOnDelete();
            $blueprint->foreign('edificio_id', 'exportacion_auditoria_edificios_edificio_fk')->references('id')->on($buildings)->restrictOnDelete();
        });

        Schema::create($table('bitacora_exportacion_auditoria'), static function (Blueprint $blueprint) use ($exports, $users): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('exportacion_id');
            $blueprint->enum('tipo', ['solicitada', 'procesamiento_iniciado', 'lease_recuperado', 'completada', 'fallida', 'descargada', 'expirada', 'archivo_eliminado']);
            $blueprint->enum('actor_tipo', ['usuario', 'sistema']);
            $blueprint->uuid('actor_user_id')->nullable();
            $blueprint->unsignedSmallInteger('intento')->nullable();
            $blueprint->string('error_codigo', 100)->nullable();
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->index(['exportacion_id', 'created_at'], 'bitacora_exportacion_auditoria_fecha_idx');
            $blueprint->foreign('exportacion_id', 'bitacora_exportacion_auditoria_exportacion_fk')->references('id')->on($exports)->restrictOnDelete();
            $blueprint->foreign('actor_user_id', 'bitacora_exportacion_auditoria_actor_fk')->references('id')->on($users)->restrictOnDelete();
        });

        Schema::create($table('limpieza_archivos_exportacion'), static function (Blueprint $blueprint) use ($exports): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('exportacion_id');
            $blueprint->string('disco', 100);
            $blueprint->string('ruta_privada', 500);
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->unique(['disco', 'ruta_privada'], 'limpieza_archivos_exportacion_ruta_uq');
            $blueprint->index('created_at', 'limpieza_archivos_exportacion_fecha_idx');
            $blueprint->foreign('exportacion_id', 'limpieza_archivos_exportacion_exportacion_fk')->references('id')->on($exports)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['limpieza_archivos_exportacion', 'bitacora_exportacion_auditoria', 'exportacion_auditoria_edificios', 'exportaciones_auditoria'] as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se puede retirar la infraestructura de exportaciones mientras exista historial.');
            }
        }
        foreach (['limpieza_archivos_exportacion', 'bitacora_exportacion_auditoria', 'exportacion_auditoria_edificios', 'exportaciones_auditoria'] as $name) {
            Schema::dropIfExists($this->table($name));
        }
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
