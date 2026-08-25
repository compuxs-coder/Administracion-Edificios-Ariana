<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');

        $this->createOverlapGuard(
            schema: $schema,
            table: 'departamento_parqueaderos',
            parentTable: 'parqueaderos',
            foreignKey: 'parqueadero_id',
            function: 'guard_parqueadero_assignment_overlap',
            trigger: 'dep_parq_overlap_guard',
            label: 'parqueadero',
        );
        $this->createOverlapGuard(
            schema: $schema,
            table: 'departamento_bodegas',
            parentTable: 'bodegas',
            foreignKey: 'bodega_id',
            function: 'guard_bodega_assignment_overlap',
            trigger: 'dep_bod_overlap_guard',
            label: 'bodega',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');

        DB::unprepared("DROP TRIGGER IF EXISTS dep_parq_overlap_guard ON \"{$schema}\".\"departamento_parqueaderos\"");
        DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_parqueadero_assignment_overlap\"()");
        DB::unprepared("DROP TRIGGER IF EXISTS dep_bod_overlap_guard ON \"{$schema}\".\"departamento_bodegas\"");
        DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_bodega_assignment_overlap\"()");
    }

    private function createOverlapGuard(
        string $schema,
        string $table,
        string $parentTable,
        string $foreignKey,
        string $function,
        string $trigger,
        string $label,
    ): void {
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."{$function}"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                PERFORM 1
                FROM "{$schema}"."{$parentTable}"
                WHERE id = NEW."{$foreignKey}"
                FOR UPDATE;

                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."{$table}" AS existing
                    WHERE existing."{$foreignKey}" = NEW."{$foreignKey}"
                      AND existing.id <> NEW.id
                      AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::timestamptz)
                      AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::timestamptz)
                ) THEN
                    RAISE EXCEPTION 'El historial del {$label} contiene asignaciones superpuestas.'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER {$trigger}
            BEFORE INSERT OR UPDATE OF "{$foreignKey}", fecha_inicio, fecha_fin
            ON "{$schema}"."{$table}"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."{$function}"();
            SQL);
    }
};
