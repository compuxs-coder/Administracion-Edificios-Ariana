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
        $qualified = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $plans = $qualified('planes_mantenimiento_preventivo');
        $occurrences = $qualified('ocurrencias_mantenimiento_preventivo');
        $logs = $qualified('bitacora_plan_mantenimiento');
        $buildings = $qualified('edificios');
        $memberships = $qualified('edificio_usuario');
        $providers = $qualified('proveedor_edificio');
        $contracts = $qualified('contratos_proveedor');

        Schema::create($plans, function (Blueprint $table) use ($buildings, $memberships, $providers, $contracts, $qualified): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->string('codigo', 40);
            $table->string('titulo', 180);
            $table->text('descripcion');
            $table->enum('prioridad', ['baja', 'media', 'alta', 'critica']);
            $table->enum('unidad_recurrencia', ['diaria', 'semanal', 'mensual', 'anual']);
            $table->unsignedSmallInteger('intervalo_recurrencia');
            $table->unsignedSmallInteger('dias_anticipacion')->default(0);
            $table->enum('estado', ['inactivo', 'activo'])->default('inactivo');
            $table->date('fecha_ancla')->nullable();
            $table->unsignedBigInteger('secuencia_siguiente')->default(0);
            $table->date('proxima_fecha_programada')->nullable();
            $table->uuid('torre_id')->nullable();
            $table->uuid('piso_id')->nullable();
            $table->uuid('departamento_id')->nullable();
            $table->uuid('parqueadero_id')->nullable();
            $table->uuid('bodega_id')->nullable();
            $table->string('ubicacion_detalle', 500)->nullable();
            $table->uuid('proveedor_id')->nullable();
            $table->uuid('contrato_id')->nullable();
            $table->uuid('creado_por_user_id');
            $table->uuid('actualizado_por_user_id');
            $table->uuid('estado_actualizado_por')->nullable();
            $table->timestampTz('estado_actualizado_at')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'planes_mantenimiento_scope_uq');
            $table->unique(['edificio_id', 'codigo'], 'planes_mantenimiento_codigo_uq');
            $table->index(['edificio_id', 'estado', 'proxima_fecha_programada'], 'planes_mantenimiento_programacion_idx');
            $table->foreign('edificio_id', 'planes_mantenimiento_edificio_fk')->references('id')->on($buildings)->restrictOnDelete();
            foreach (['creado_por_user_id' => 'creador', 'actualizado_por_user_id' => 'actualizador', 'estado_actualizado_por' => 'estado_actor'] as $field => $suffix) {
                $table->foreign(['edificio_id', $field], "planes_mantenimiento_{$suffix}_fk")
                    ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            }
            $table->foreign(['edificio_id', 'proveedor_id'], 'planes_mantenimiento_proveedor_fk')
                ->references(['edificio_id', 'proveedor_id'])->on($providers)->restrictOnDelete();
            $table->foreign(['edificio_id', 'contrato_id', 'proveedor_id'], 'planes_mantenimiento_contrato_fk')
                ->references(['edificio_id', 'id', 'proveedor_id'])->on($contracts)->restrictOnDelete();
            foreach (['torre_id' => 'torres', 'piso_id' => 'pisos', 'departamento_id' => 'departamentos', 'parqueadero_id' => 'parqueaderos', 'bodega_id' => 'bodegas'] as $field => $source) {
                $table->foreign(['edificio_id', $field], "planes_mantenimiento_{$source}_fk")
                    ->references(['edificio_id', 'id'])->on($qualified($source))->restrictOnDelete();
            }
        });

        Schema::create($occurrences, function (Blueprint $table) use ($plans): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('plan_id');
            $table->date('fecha_programada');
            $table->enum('estado', ['pendiente', 'generada', 'bloqueada', 'omitida'])->default('pendiente');
            $table->text('motivo')->nullable();
            $table->timestampTz('procesada_at', 6)->nullable();
            $table->timestampsTz();
            $table->unique(['edificio_id', 'id'], 'ocurrencias_mantenimiento_scope_uq');
            $table->unique(['plan_id', 'fecha_programada'], 'ocurrencias_mantenimiento_plan_fecha_uq');
            $table->index(['edificio_id', 'estado', 'fecha_programada'], 'ocurrencias_mantenimiento_estado_idx');
            $table->foreign(['edificio_id', 'plan_id'], 'ocurrencias_mantenimiento_plan_fk')
                ->references(['edificio_id', 'id'])->on($plans)->restrictOnDelete();
        });

        Schema::create($logs, function (Blueprint $table) use ($plans, $memberships): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('plan_id');
            $table->enum('tipo', ['creacion', 'cambio_datos', 'activacion', 'pausa', 'generacion', 'bloqueo', 'reintento', 'omision']);
            $table->enum('actor_tipo', ['usuario', 'sistema']);
            $table->uuid('actor_user_id')->nullable();
            $table->json('detalle')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['plan_id', 'created_at'], 'bitacora_plan_mantenimiento_fecha_idx');
            $table->foreign(['edificio_id', 'plan_id'], 'bitacora_plan_mantenimiento_plan_fk')
                ->references(['edificio_id', 'id'])->on($plans)->restrictOnDelete();
            $table->foreign(['edificio_id', 'actor_user_id'], 'bitacora_plan_mantenimiento_actor_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });

        $prefix = $pgsql ? '"'.$schema.'".' : '';
        DB::statement("CREATE UNIQUE INDEX ocurrencias_mantenimiento_abierta_uq ON {$prefix}\"ocurrencias_mantenimiento_preventivo\" (\"plan_id\") WHERE \"estado\" IN ('pendiente', 'bloqueada')");
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        foreach (['bitacora_plan_mantenimiento', 'ocurrencias_mantenimiento_preventivo', 'planes_mantenimiento_preventivo'] as $historyTable) {
            if (DB::table($table($historyTable))->exists()) {
                throw new RuntimeException('No se puede retirar mantenimiento preventivo mientras exista historial.');
            }
        }
        Schema::dropIfExists($table('bitacora_plan_mantenimiento'));
        Schema::dropIfExists($table('ocurrencias_mantenimiento_preventivo'));
        Schema::dropIfExists($table('planes_mantenimiento_preventivo'));
    }
};
