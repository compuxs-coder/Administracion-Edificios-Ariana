<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? $schema.'.'.$name : $name;
        $orders = $table('ordenes_operativas');
        $assignments = $table('asignaciones_orden_operativa');

        $invalidOrderSnapshots = $pgsql
            ? DB::selectOne("SELECT 1 FROM {$orders} WHERE reportante_snapshot IS NOT NULL AND (jsonb_typeof(reportante_snapshot::jsonb) <> 'object' OR reportante_snapshot::jsonb = '{}'::jsonb) LIMIT 1")
            : DB::selectOne("SELECT 1 FROM {$orders} WHERE reportante_snapshot IS NOT NULL AND CASE WHEN json_valid(reportante_snapshot) = 0 THEN 1 WHEN json_type(reportante_snapshot) <> 'object' THEN 1 WHEN json(reportante_snapshot) = '{}' THEN 1 ELSE 0 END = 1 LIMIT 1");
        $invalidAssignmentSnapshots = $pgsql
            ? DB::selectOne("SELECT 1 FROM {$assignments} WHERE jsonb_typeof(responsable_snapshot::jsonb) <> 'object' OR responsable_snapshot::jsonb = '{}'::jsonb LIMIT 1")
            : DB::selectOne("SELECT 1 FROM {$assignments} WHERE CASE WHEN json_valid(responsable_snapshot) = 0 THEN 1 WHEN json_type(responsable_snapshot) <> 'object' THEN 1 WHEN json(responsable_snapshot) = '{}' THEN 1 ELSE 0 END = 1 LIMIT 1");
        if ($invalidOrderSnapshots !== null || $invalidAssignmentSnapshots !== null) {
            throw new RuntimeException('Existen snapshots operativos inválidos; corrija los datos antes de continuar.');
        }

        $overlap = DB::selectOne(<<<SQL
            SELECT 1
            FROM {$assignments} first_assignment
            JOIN {$assignments} second_assignment
              ON second_assignment.orden_operativa_id = first_assignment.orden_operativa_id
             AND second_assignment.id <> first_assignment.id
            WHERE first_assignment.fecha_inicio < COALESCE(second_assignment.fecha_fin, '9999-12-31 23:59:59+00')
              AND second_assignment.fecha_inicio < COALESCE(first_assignment.fecha_fin, '9999-12-31 23:59:59+00')
            LIMIT 1
            SQL);
        if ($overlap !== null) {
            throw new RuntimeException('Existen intervalos de responsables solapados; corrija los datos antes de continuar.');
        }

        if (! $pgsql) {
            $this->createSqliteGuards();

            return;
        }

        DB::statement("ALTER TABLE \"{$schema}\".\"ordenes_operativas\" ADD CONSTRAINT ordenes_reportante_snapshot_object_check CHECK (reportante_snapshot IS NULL OR (jsonb_typeof(reportante_snapshot::jsonb) = 'object' AND reportante_snapshot::jsonb <> '{}'::jsonb))");
        DB::statement("ALTER TABLE \"{$schema}\".\"asignaciones_orden_operativa\" ADD CONSTRAINT asignaciones_snapshot_object_check CHECK (jsonb_typeof(responsable_snapshot::jsonb) = 'object' AND responsable_snapshot::jsonb <> '{}'::jsonb)");
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_fresh_operaciones_transition"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE requires_reason boolean;
            BEGIN
                IF NEW.estado IS DISTINCT FROM OLD.estado THEN
                    IF NEW.estado_actualizado_at IS NULL
                       OR (OLD.estado_actualizado_at IS NOT NULL AND NEW.estado_actualizado_at <= OLD.estado_actualizado_at) THEN
                        RAISE EXCEPTION 'Cada transición requiere una fecha posterior a la transición anterior.' USING ERRCODE = '23514';
                    END IF;
                    requires_reason := NEW.estado = 'cancelada'
                        OR (OLD.estado IN ('resuelta', 'cerrada') AND NEW.estado = 'en_progreso');
                    IF requires_reason AND COALESCE(btrim(NEW.motivo_estado), '') = '' THEN
                        RAISE EXCEPTION 'La cancelación o reapertura requiere motivo.' USING ERRCODE = '23514';
                    END IF;
                    IF NOT requires_reason AND NEW.motivo_estado IS NOT NULL THEN
                        RAISE EXCEPTION 'Una transición normal no admite motivo de cancelación o reapertura.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ordenes_transicion_fresh_guard
            BEFORE UPDATE ON "{$schema}"."ordenes_operativas"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_fresh_operaciones_transition"();

            CREATE FUNCTION "{$schema}"."guard_asignacion_intervalo"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                PERFORM 1 FROM "{$schema}"."ordenes_operativas"
                WHERE id = NEW.orden_operativa_id AND edificio_id = NEW.edificio_id
                FOR UPDATE;
                IF EXISTS (
                    SELECT 1 FROM "{$schema}"."asignaciones_orden_operativa" existing
                    WHERE existing.orden_operativa_id = NEW.orden_operativa_id
                      AND existing.id <> NEW.id
                      AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::timestamptz)
                      AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::timestamptz)
                ) THEN
                    RAISE EXCEPTION 'Los intervalos de responsables no pueden solaparse.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER asignaciones_intervalo_guard
            BEFORE INSERT ON "{$schema}"."asignaciones_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_asignacion_intervalo"();

            CREATE FUNCTION "{$schema}"."verify_cancelled_order_assignment"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE target_operation_id uuid;
            DECLARE target_building_id uuid;
            BEGIN
                IF TG_TABLE_NAME = 'ordenes_operativas' THEN
                    target_operation_id := NEW.id;
                    target_building_id := NEW.edificio_id;
                ELSE
                    target_operation_id := NEW.orden_operativa_id;
                    target_building_id := NEW.edificio_id;
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."ordenes_operativas" operation
                    JOIN "{$schema}"."asignaciones_orden_operativa" assignment
                      ON assignment.orden_operativa_id = operation.id
                     AND assignment.edificio_id = operation.edificio_id
                     AND assignment.fecha_fin IS NULL
                    WHERE operation.id = target_operation_id
                      AND operation.edificio_id = target_building_id
                      AND operation.estado = 'cancelada'
                ) THEN
                    RAISE EXCEPTION 'Una orden cancelada no puede conservar un responsable vigente.' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            \$\$;
            CREATE CONSTRAINT TRIGGER ordenes_cancelacion_deferred_guard
            AFTER UPDATE ON "{$schema}"."ordenes_operativas"
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."verify_cancelled_order_assignment"();
            CREATE CONSTRAINT TRIGGER asignaciones_cancelacion_deferred_guard
            AFTER INSERT OR UPDATE ON "{$schema}"."asignaciones_orden_operativa"
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."verify_cancelled_order_assignment"();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            foreach ([
                'ordenes_reportante_snapshot_object_guard',
                'ordenes_reportante_snapshot_object_update_guard',
                'asignaciones_snapshot_object_guard',
                'ordenes_transicion_fresh_guard',
                'asignaciones_intervalo_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS asignaciones_cancelacion_deferred_guard ON "{$schema}"."asignaciones_orden_operativa";
            DROP TRIGGER IF EXISTS ordenes_cancelacion_deferred_guard ON "{$schema}"."ordenes_operativas";
            DROP FUNCTION IF EXISTS "{$schema}"."verify_cancelled_order_assignment"();
            DROP TRIGGER IF EXISTS asignaciones_intervalo_guard ON "{$schema}"."asignaciones_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_asignacion_intervalo"();
            DROP TRIGGER IF EXISTS ordenes_transicion_fresh_guard ON "{$schema}"."ordenes_operativas";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_fresh_operaciones_transition"();
            ALTER TABLE "{$schema}"."asignaciones_orden_operativa" DROP CONSTRAINT IF EXISTS asignaciones_snapshot_object_check;
            ALTER TABLE "{$schema}"."ordenes_operativas" DROP CONSTRAINT IF EXISTS ordenes_reportante_snapshot_object_check;
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ordenes_reportante_snapshot_object_guard
            BEFORE INSERT ON ordenes_operativas
            WHEN NEW.reportante_snapshot IS NOT NULL AND CASE
                WHEN json_valid(NEW.reportante_snapshot) = 0 THEN 1
                WHEN json_type(NEW.reportante_snapshot) <> 'object' THEN 1
                WHEN json(NEW.reportante_snapshot) = '{}' THEN 1
                ELSE 0
            END = 1
            BEGIN SELECT RAISE(ABORT, 'El snapshot del reportante debe ser un objeto JSON no vacío.'); END;

            CREATE TRIGGER ordenes_reportante_snapshot_object_update_guard
            BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.reportante_snapshot IS NOT NULL AND CASE
                WHEN json_valid(NEW.reportante_snapshot) = 0 THEN 1
                WHEN json_type(NEW.reportante_snapshot) <> 'object' THEN 1
                WHEN json(NEW.reportante_snapshot) = '{}' THEN 1
                ELSE 0
            END = 1
            BEGIN SELECT RAISE(ABORT, 'El snapshot del reportante debe ser un objeto JSON no vacío.'); END;

            CREATE TRIGGER asignaciones_snapshot_object_guard
            BEFORE INSERT ON asignaciones_orden_operativa
            WHEN CASE
                WHEN json_valid(NEW.responsable_snapshot) = 0 THEN 1
                WHEN json_type(NEW.responsable_snapshot) <> 'object' THEN 1
                WHEN json(NEW.responsable_snapshot) = '{}' THEN 1
                ELSE 0
            END = 1
            BEGIN SELECT RAISE(ABORT, 'El snapshot del responsable debe ser un objeto JSON no vacío.'); END;

            CREATE TRIGGER ordenes_transicion_fresh_guard
            BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.estado IS NOT OLD.estado AND (
                NEW.estado_actualizado_at IS NULL
                OR (OLD.estado_actualizado_at IS NOT NULL AND NEW.estado_actualizado_at <= OLD.estado_actualizado_at)
                OR ((NEW.estado = 'cancelada' OR (OLD.estado IN ('resuelta', 'cerrada') AND NEW.estado = 'en_progreso'))
                    AND trim(COALESCE(NEW.motivo_estado, '')) = '')
                OR (NOT (NEW.estado = 'cancelada' OR (OLD.estado IN ('resuelta', 'cerrada') AND NEW.estado = 'en_progreso'))
                    AND NEW.motivo_estado IS NOT NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'La transición requiere trazabilidad nueva y un motivo coherente.'); END;

            CREATE TRIGGER asignaciones_intervalo_guard
            BEFORE INSERT ON asignaciones_orden_operativa
            WHEN EXISTS (
                SELECT 1 FROM asignaciones_orden_operativa existing
                WHERE existing.orden_operativa_id = NEW.orden_operativa_id
                  AND existing.id <> NEW.id
                  AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, '9999-12-31 23:59:59')
                  AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, '9999-12-31 23:59:59')
            )
            BEGIN SELECT RAISE(ABORT, 'Los intervalos de responsables no pueden solaparse.'); END;
            SQL);
    }
};
