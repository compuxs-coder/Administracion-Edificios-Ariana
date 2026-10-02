<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if (! $pgsql) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ordenes_cancelacion_responsable_guard
                BEFORE UPDATE OF estado ON ordenes_operativas
                WHEN NEW.estado = 'cancelada' AND EXISTS (
                    SELECT 1 FROM asignaciones_orden_operativa a
                    WHERE a.orden_operativa_id = NEW.id AND a.fecha_fin IS NULL
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Una orden cancelada no puede conservar un responsable vigente.');
                END;
                SQL);

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_cancelacion_orden_responsable"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.estado = 'cancelada' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."asignaciones_orden_operativa" a
                    WHERE a.orden_operativa_id = NEW.id AND a.fecha_fin IS NULL
                ) THEN
                    RAISE EXCEPTION 'Una orden cancelada no puede conservar un responsable vigente.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ordenes_cancelacion_responsable_guard
            BEFORE UPDATE OF estado ON "{$schema}"."ordenes_operativas"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_cancelacion_orden_responsable"();

            CREATE FUNCTION "{$schema}"."lock_orden_para_asignacion"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE order_state text;
            BEGIN
                SELECT estado INTO order_state
                FROM "{$schema}"."ordenes_operativas"
                WHERE id = NEW.orden_operativa_id AND edificio_id = NEW.edificio_id
                FOR UPDATE;
                IF order_state = 'cancelada' THEN
                    RAISE EXCEPTION 'Una orden cancelada no admite responsables.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER asignaciones_orden_parent_lock
            BEFORE INSERT ON "{$schema}"."asignaciones_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."lock_orden_para_asignacion"();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ordenes_cancelacion_responsable_guard');

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS asignaciones_orden_parent_lock ON "{$schema}"."asignaciones_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."lock_orden_para_asignacion"();
            DROP TRIGGER IF EXISTS ordenes_cancelacion_responsable_guard ON "{$schema}"."ordenes_operativas";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_cancelacion_orden_responsable"();
            SQL);
    }
};
