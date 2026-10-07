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
        $orders = $pgsql ? "{$schema}.ordenes_operativas" : 'ordenes_operativas';
        $logs = $pgsql ? "{$schema}.bitacora_orden_operativa" : 'bitacora_orden_operativa';
        $occurrences = $pgsql ? "{$schema}.ocurrencias_mantenimiento_preventivo" : 'ocurrencias_mantenimiento_preventivo';

        if ($pgsql) {
            Schema::table($orders, function (Blueprint $table): void {
                $table->enum('origen', ['manual', 'programacion_preventiva'])->default('manual')->after('tipo');
                $table->uuid('ocurrencia_mantenimiento_id')->nullable()->after('origen');
                $table->enum('creada_por_tipo', ['usuario', 'sistema'])->default('usuario')->after('creada_por_user_id');
            });
            Schema::table($logs, function (Blueprint $table): void {
                $table->enum('actor_tipo', ['usuario', 'sistema'])->default('usuario')->after('tipo');
            });
            DB::unprepared(<<<SQL
                ALTER TABLE "{$schema}"."ordenes_operativas" DROP CONSTRAINT IF EXISTS ordenes_operativas_tipo_check;
                ALTER TABLE "{$schema}"."ordenes_operativas" ADD CONSTRAINT ordenes_operativas_tipo_check CHECK (tipo IN ('incidencia', 'solicitud', 'mantenimiento_preventivo'));
                ALTER TABLE "{$schema}"."ordenes_operativas" ALTER COLUMN creada_por_user_id DROP NOT NULL;
                ALTER TABLE "{$schema}"."bitacora_orden_operativa" ALTER COLUMN actor_user_id DROP NOT NULL;
                SQL);
            Schema::table($orders, function (Blueprint $table) use ($occurrences): void {
                $table->unique('ocurrencia_mantenimiento_id', 'ordenes_ocurrencia_mantenimiento_uq');
                $table->foreign(['edificio_id', 'ocurrencia_mantenimiento_id'], 'ordenes_ocurrencia_mantenimiento_fk')
                    ->references(['edificio_id', 'id'])->on($occurrences)->restrictOnDelete();
            });
        } else {
            // SQLite rebuilds tables for column changes. Related triggers must be removed too,
            // otherwise they reference the temporary table gap during the rename.
            $triggers = collect(DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL"));
            foreach ($triggers as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger->name).'"');
            }
            Schema::table($orders, function (Blueprint $table) use ($occurrences): void {
                $table->enum('origen', ['manual', 'programacion_preventiva'])->default('manual');
                $table->uuid('ocurrencia_mantenimiento_id')->nullable();
                $table->enum('creada_por_tipo', ['usuario', 'sistema'])->default('usuario');
                $table->string('tipo')->change();
                $table->uuid('creada_por_user_id')->nullable()->change();
                $table->unique('ocurrencia_mantenimiento_id', 'ordenes_ocurrencia_mantenimiento_uq');
                $table->foreign(['edificio_id', 'ocurrencia_mantenimiento_id'], 'ordenes_ocurrencia_mantenimiento_fk')
                    ->references(['edificio_id', 'id'])->on($occurrences)->restrictOnDelete();
            });
            Schema::table($logs, function (Blueprint $table): void {
                $table->enum('actor_tipo', ['usuario', 'sistema'])->default('usuario');
                $table->uuid('actor_user_id')->nullable()->change();
            });
            foreach ($triggers as $trigger) {
                DB::unprepared($trigger->sql);
            }
        }

    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $orders = $pgsql ? "{$schema}.ordenes_operativas" : 'ordenes_operativas';
        $logs = $pgsql ? "{$schema}.bitacora_orden_operativa" : 'bitacora_orden_operativa';
        if (DB::table($orders)->where('origen', 'programacion_preventiva')->exists()) {
            throw new RuntimeException('No se puede retirar mantenimiento preventivo mientras existan órdenes generadas.');
        }

        $triggers = collect();
        if (! $pgsql) {
            $triggers = collect(DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL"));
            foreach ($triggers as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger->name).'"');
            }
        }

        Schema::table($orders, function (Blueprint $table) use ($pgsql): void {
            $pgsql
                ? $table->dropForeign('ordenes_ocurrencia_mantenimiento_fk')
                : $table->dropForeign(['edificio_id', 'ocurrencia_mantenimiento_id']);
            $table->dropUnique('ordenes_ocurrencia_mantenimiento_uq');
            $table->dropColumn(['origen', 'ocurrencia_mantenimiento_id', 'creada_por_tipo']);
            if (! $pgsql) {
                $table->enum('tipo', ['incidencia', 'solicitud'])->change();
                $table->uuid('creada_por_user_id')->nullable(false)->change();
            }
        });
        Schema::table($logs, function (Blueprint $table) use ($pgsql): void {
            $table->dropColumn('actor_tipo');
            if (! $pgsql) {
                $table->uuid('actor_user_id')->nullable(false)->change();
            }
        });

        if ($pgsql) {
            DB::unprepared(<<<SQL
                ALTER TABLE "{$schema}"."ordenes_operativas" DROP CONSTRAINT IF EXISTS ordenes_operativas_tipo_check;
                ALTER TABLE "{$schema}"."ordenes_operativas" ADD CONSTRAINT ordenes_operativas_tipo_check CHECK (tipo IN ('incidencia', 'solicitud'));
                ALTER TABLE "{$schema}"."ordenes_operativas" ALTER COLUMN creada_por_user_id SET NOT NULL;
                ALTER TABLE "{$schema}"."bitacora_orden_operativa" ALTER COLUMN actor_user_id SET NOT NULL;
                SQL);
        } else {
            foreach ($triggers as $trigger) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
