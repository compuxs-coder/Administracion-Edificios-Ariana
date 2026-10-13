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
        $spaces = $table('espacios_comunes');
        $rules = $table('reglas_espacio_comun');
        $schedules = $table('horarios_regla_espacio_comun');
        $reservations = $table('reservas_espacio_comun');
        $logs = $table('bitacora_areas_comunes');
        $buildings = $table('edificios');
        $memberships = $table('edificio_usuario');

        Schema::create($spaces, function (Blueprint $blueprint) use ($buildings, $memberships, $table): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->string('codigo', 40);
            $blueprint->string('nombre', 180);
            $blueprint->text('descripcion')->nullable();
            $blueprint->unsignedSmallInteger('capacidad');
            $blueprint->uuid('torre_id')->nullable();
            $blueprint->uuid('piso_id')->nullable();
            $blueprint->string('ubicacion_detalle', 500)->nullable();
            $blueprint->enum('estado', ['activo', 'inactivo'])->default('activo');
            $blueprint->uuid('creado_por_user_id');
            $blueprint->uuid('actualizado_por_user_id');
            $blueprint->uuid('estado_actualizado_por')->nullable();
            $blueprint->timestampTz('estado_actualizado_at', 6)->nullable();
            $blueprint->timestampsTz(6);

            $blueprint->unique(['edificio_id', 'id'], 'espacios_comunes_scope_uq');
            $blueprint->unique(['edificio_id', 'codigo'], 'espacios_comunes_codigo_uq');
            $blueprint->index(['edificio_id', 'estado', 'nombre'], 'espacios_comunes_estado_idx');
            $blueprint->foreign('edificio_id', 'espacios_comunes_edificio_fk')->references('id')->on($buildings)->restrictOnDelete();
            foreach (['creado_por_user_id' => 'creador', 'actualizado_por_user_id' => 'actualizador', 'estado_actualizado_por' => 'estado_actor'] as $field => $suffix) {
                $blueprint->foreign(['edificio_id', $field], "espacios_comunes_{$suffix}_fk")
                    ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            }
            $blueprint->foreign(['edificio_id', 'torre_id'], 'espacios_comunes_torre_fk')->references(['edificio_id', 'id'])->on($table('torres'))->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'piso_id'], 'espacios_comunes_piso_fk')->references(['edificio_id', 'id'])->on($table('pisos'))->restrictOnDelete();
        });

        Schema::create($rules, function (Blueprint $blueprint) use ($spaces, $memberships): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('espacio_comun_id');
            $blueprint->date('vigente_desde');
            $blueprint->date('vigente_hasta')->nullable();
            $blueprint->boolean('requiere_aprobacion')->default(false);
            $blueprint->unsignedSmallInteger('duracion_minima_minutos');
            $blueprint->unsignedSmallInteger('duracion_maxima_minutos');
            $blueprint->unsignedInteger('antelacion_minima_minutos')->default(0);
            $blueprint->unsignedSmallInteger('antelacion_maxima_dias');
            $blueprint->unsignedSmallInteger('buffer_antes_minutos')->default(0);
            $blueprint->unsignedSmallInteger('buffer_despues_minutos')->default(0);
            $blueprint->unsignedSmallInteger('aforo_maximo');
            $blueprint->text('reglamento')->nullable();
            $blueprint->uuid('creada_por_user_id');
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->unique(['edificio_id', 'id'], 'reglas_espacio_scope_uq');
            $blueprint->unique(['edificio_id', 'id', 'espacio_comun_id'], 'reglas_espacio_reserva_scope_uq');
            $blueprint->unique(['espacio_comun_id', 'vigente_desde'], 'reglas_espacio_vigencia_uq');
            $blueprint->index(['espacio_comun_id', 'vigente_desde', 'vigente_hasta'], 'reglas_espacio_periodo_idx');
            $blueprint->foreign(['edificio_id', 'espacio_comun_id'], 'reglas_espacio_area_fk')->references(['edificio_id', 'id'])->on($spaces)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'creada_por_user_id'], 'reglas_espacio_actor_fk')->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });

        Schema::create($schedules, function (Blueprint $blueprint) use ($rules): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('regla_id');
            $blueprint->unsignedTinyInteger('dia_semana');
            $blueprint->time('hora_inicio');
            $blueprint->time('hora_fin');
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->unique(['regla_id', 'dia_semana', 'hora_inicio', 'hora_fin'], 'horarios_regla_tramo_uq');
            $blueprint->index(['regla_id', 'dia_semana'], 'horarios_regla_dia_idx');
            $blueprint->foreign(['edificio_id', 'regla_id'], 'horarios_regla_regla_fk')->references(['edificio_id', 'id'])->on($rules)->restrictOnDelete();
        });

        Schema::create($reservations, function (Blueprint $blueprint) use ($spaces, $rules, $memberships, $table): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('espacio_comun_id');
            $blueprint->uuid('regla_id');
            $blueprint->uuid('ocupacion_id');
            $blueprint->uuid('departamento_id');
            $blueprint->uuid('residente_id');
            $blueprint->json('reservante_snapshot');
            $blueprint->json('regla_snapshot');
            $blueprint->timestampTz('inicio_at', 6);
            $blueprint->timestampTz('fin_at', 6);
            $blueprint->timestampTz('bloqueo_inicio_at', 6);
            $blueprint->timestampTz('bloqueo_fin_at', 6);
            $blueprint->unsignedSmallInteger('cantidad_asistentes');
            $blueprint->enum('estado', ['pendiente', 'confirmada', 'rechazada', 'cancelada']);
            $blueprint->text('observaciones')->nullable();
            $blueprint->uuid('creada_por_user_id');
            $blueprint->uuid('resuelta_por_user_id')->nullable();
            $blueprint->timestampTz('resuelta_at', 6)->nullable();
            $blueprint->text('motivo_resolucion')->nullable();
            $blueprint->timestampsTz(6);

            $blueprint->unique(['edificio_id', 'id'], 'reservas_espacio_scope_uq');
            $blueprint->unique(['edificio_id', 'id', 'espacio_comun_id'], 'reservas_espacio_log_scope_uq');
            $blueprint->index(['edificio_id', 'estado', 'inicio_at'], 'reservas_espacio_estado_idx');
            $blueprint->index(['espacio_comun_id', 'estado', 'bloqueo_inicio_at', 'bloqueo_fin_at'], 'reservas_espacio_agenda_idx');
            $blueprint->foreign(['edificio_id', 'espacio_comun_id'], 'reservas_espacio_area_fk')->references(['edificio_id', 'id'])->on($spaces)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'regla_id', 'espacio_comun_id'], 'reservas_espacio_regla_fk')->references(['edificio_id', 'id', 'espacio_comun_id'])->on($rules)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'ocupacion_id', 'departamento_id', 'residente_id'], 'reservas_espacio_ocupacion_fk')
                ->references(['edificio_id', 'id', 'departamento_id', 'residente_id'])->on($table('departamento_residentes'))->restrictOnDelete();
            foreach (['creada_por_user_id' => 'creador', 'resuelta_por_user_id' => 'resolutor'] as $field => $suffix) {
                $blueprint->foreign(['edificio_id', $field], "reservas_espacio_{$suffix}_fk")
                    ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            }
        });

        Schema::create($logs, function (Blueprint $blueprint) use ($spaces, $rules, $reservations, $memberships): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('edificio_id');
            $blueprint->uuid('espacio_comun_id');
            $blueprint->uuid('regla_id')->nullable();
            $blueprint->uuid('reserva_id')->nullable();
            $blueprint->enum('tipo', ['espacio_creado', 'espacio_actualizado', 'espacio_activado', 'espacio_inactivado', 'regla_creada', 'reserva_creada', 'reserva_aprobada', 'reserva_rechazada', 'reserva_cancelada']);
            $blueprint->uuid('actor_user_id');
            $blueprint->json('detalle')->nullable();
            $blueprint->timestampTz('created_at', 6)->useCurrent();

            $blueprint->index(['espacio_comun_id', 'created_at'], 'bitacora_areas_fecha_idx');
            $blueprint->foreign(['edificio_id', 'espacio_comun_id'], 'bitacora_areas_espacio_fk')->references(['edificio_id', 'id'])->on($spaces)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'regla_id'], 'bitacora_areas_regla_fk')->references(['edificio_id', 'id'])->on($rules)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'reserva_id', 'espacio_comun_id'], 'bitacora_areas_reserva_fk')->references(['edificio_id', 'id', 'espacio_comun_id'])->on($reservations)->restrictOnDelete();
            $blueprint->foreign(['edificio_id', 'actor_user_id'], 'bitacora_areas_actor_fk')->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $tables = ['bitacora_areas_comunes', 'reservas_espacio_comun', 'horarios_regla_espacio_comun', 'reglas_espacio_comun', 'espacios_comunes'];
        foreach ($tables as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se puede retirar Áreas comunes mientras exista historial.');
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
