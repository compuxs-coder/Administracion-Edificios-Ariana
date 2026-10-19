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
        $messages = $table('comunicados');
        $documents = $table('documentos_comunicado');
        $deliveries = $table('entregas_comunicado');
        $recipients = $table('destinatarios_comunicado');
        $logs = $table('bitacora_comunicacion');
        $buildings = $table('edificios');
        $memberships = $table('edificio_usuario');

        Schema::create($messages, function (Blueprint $blueprint) use ($buildings, $memberships): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->string('titulo', 180);
            $blueprint->text('cuerpo');
            $blueprint->boolean('audiencia_propietarios')->default(false);
            $blueprint->boolean('audiencia_residentes')->default(false);
            $blueprint->enum('estado', ['borrador', 'publicado', 'archivado'])->default('borrador');
            $blueprint->unsignedInteger('version')->default(1);
            $blueprint->uuid('creado_por_user_id');
            $blueprint->uuid('actualizado_por_user_id');
            $blueprint->uuid('publicado_por_user_id')->nullable();
            $blueprint->timestampTz('publicado_at', 6)->nullable();
            $blueprint->uuid('archivado_por_user_id')->nullable();
            $blueprint->timestampTz('archivado_at', 6)->nullable();
            $blueprint->unsignedInteger('total_candidatos')->default(0);
            $blueprint->unsignedInteger('total_entregas')->default(0);
            $blueprint->unsignedInteger('total_omitidos')->default(0);
            $blueprint->timestampsTz(6);

            $blueprint->unique(['edificio_id', 'id'], 'comunicados_scope_uq');
            $blueprint->index(['edificio_id', 'estado', 'publicado_at'], 'comunicados_estado_idx');
            $blueprint->foreign('edificio_id', 'comunicados_edificio_fk')->references('id')->on($buildings)->restrictOnDelete();
            foreach (['creado_por_user_id' => 'creador', 'actualizado_por_user_id' => 'actualizador', 'publicado_por_user_id' => 'publicador', 'archivado_por_user_id' => 'archivador'] as $field => $suffix) {
                $blueprint->foreign(['edificio_id', $field], "comunicados_{$suffix}_fk")
                    ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            }
        });

        Schema::create($documents, function (Blueprint $blueprint) use ($messages, $memberships): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('comunicado_id');
            $blueprint->string('nombre_original', 220);
            $blueprint->string('mime_type', 40);
            $blueprint->unsignedBigInteger('tamano_bytes');
            $blueprint->char('sha256', 64);
            $blueprint->string('ruta_privada', 500)->unique('documentos_comunicado_ruta_uq');
            $blueprint->string('descripcion', 500)->nullable();
            $blueprint->uuid('subido_por_user_id');
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->unique(['edificio_id', 'id', 'comunicado_id'], 'documentos_comunicado_scope_uq');
            $blueprint->index(['comunicado_id', 'created_at'], 'documentos_comunicado_fecha_idx');
            $blueprint->foreign(['edificio_id', 'comunicado_id'], 'documentos_comunicado_fk')->references(['edificio_id', 'id'])->on($messages)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'subido_por_user_id'], 'documentos_comunicado_actor_fk')->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });

        Schema::create($deliveries, function (Blueprint $blueprint) use ($messages): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('comunicado_id');
            $blueprint->string('canal', 30)->default('email');
            $blueprint->string('destino_normalizado', 255);
            $blueprint->enum('estado', ['pendiente', 'procesando', 'reintento', 'enviada', 'fallida'])->default('pendiente');
            $blueprint->unsignedSmallInteger('intentos')->default(0);
            $blueprint->timestampTz('disponible_at', 6)->useCurrent();
            $blueprint->timestampTz('lease_until', 6)->nullable();
            $blueprint->timestampTz('ultimo_intento_at', 6)->nullable();
            $blueprint->timestampTz('enviado_at', 6)->nullable();
            $blueprint->string('proveedor_mensaje_id', 255)->nullable();
            $blueprint->string('error_codigo', 100)->nullable();
            $blueprint->string('error_detalle', 1000)->nullable();
            $blueprint->timestampsTz(6);

            $blueprint->unique(['edificio_id', 'id', 'comunicado_id'], 'entregas_comunicado_scope_uq');
            $blueprint->unique(['comunicado_id', 'canal', 'destino_normalizado'], 'entregas_comunicado_destino_uq');
            $blueprint->index(['estado', 'disponible_at', 'lease_until'], 'entregas_comunicado_cola_idx');
            $blueprint->index(['comunicado_id', 'estado'], 'entregas_comunicado_estado_idx');
            $blueprint->foreign(['edificio_id', 'comunicado_id'], 'entregas_comunicado_fk')->references(['edificio_id', 'id'])->on($messages)->restrictOnDelete();
        });

        Schema::create($recipients, function (Blueprint $blueprint) use ($messages, $deliveries, $table): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('comunicado_id');
            $blueprint->uuid('entrega_id')->nullable();
            $blueprint->uuid('tercero_id');
            $blueprint->string('nombre_snapshot', 250);
            $blueprint->string('email_snapshot', 255)->nullable();
            $blueprint->string('email_normalizado', 255)->nullable();
            $blueprint->boolean('es_propietario')->default(false);
            $blueprint->boolean('es_residente')->default(false);
            $blueprint->json('departamentos_snapshot');
            $blueprint->enum('estado', ['incluido', 'omitido']);
            $blueprint->string('motivo_omision', 100)->nullable();
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->unique(['comunicado_id', 'tercero_id'], 'destinatarios_comunicado_tercero_uq');
            $blueprint->index(['comunicado_id', 'estado'], 'destinatarios_comunicado_estado_idx');
            $blueprint->foreign(['edificio_id', 'comunicado_id'], 'destinatarios_comunicado_fk')->references(['edificio_id', 'id'])->on($messages)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'entrega_id', 'comunicado_id'], 'destinatarios_comunicado_entrega_fk')->references(['edificio_id', 'id', 'comunicado_id'])->on($deliveries)->restrictOnDelete();
            $blueprint->foreign('tercero_id', 'destinatarios_comunicado_tercero_fk')->references('id')->on($table('terceros'))->restrictOnDelete();
        });

        Schema::create($logs, function (Blueprint $blueprint) use ($messages, $memberships): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('comunicado_id');
            $blueprint->enum('tipo', ['comunicado_creado', 'comunicado_actualizado', 'documento_agregado', 'documento_retirado', 'comunicado_publicado', 'comunicado_archivado', 'entrega_reintentada']);
            $blueprint->uuid('actor_user_id');
            $blueprint->json('detalle')->nullable();
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->index(['comunicado_id', 'created_at'], 'bitacora_comunicacion_fecha_idx');
            $blueprint->foreign(['edificio_id', 'comunicado_id'], 'bitacora_comunicacion_fk')->references(['edificio_id', 'id'])->on($messages)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'actor_user_id'], 'bitacora_comunicacion_actor_fk')->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $tables = ['bitacora_comunicacion', 'destinatarios_comunicado', 'entregas_comunicado', 'documentos_comunicado', 'comunicados'];
        foreach ($tables as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se puede retirar Comunicación mientras exista historial.');
            }
        }
        foreach ($tables as $name) {
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
