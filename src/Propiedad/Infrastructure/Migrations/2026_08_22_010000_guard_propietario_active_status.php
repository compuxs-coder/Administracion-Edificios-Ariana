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

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_propietario_estado"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF NEW.estado = 'inactivo' AND EXISTS (
                    SELECT 1
                    FROM "{$schema}"."departamento_propietarios"
                    WHERE propietario_id = NEW.id AND estado = 'activa'
                ) THEN
                    RAISE EXCEPTION 'No se puede inactivar un propietario con titularidades activas.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER propietarios_estado_guard
            BEFORE UPDATE OF estado ON "{$schema}"."propietarios"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_propietario_estado"();

            CREATE FUNCTION "{$schema}"."guard_titularidad_propietario_activo"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF NEW.estado = 'activa' AND NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."propietarios"
                    WHERE id = NEW.propietario_id AND estado = 'activo'
                ) THEN
                    RAISE EXCEPTION 'La titularidad activa requiere un propietario activo.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER titularidades_propietario_activo_guard
            BEFORE INSERT OR UPDATE OF propietario_id, estado
            ON "{$schema}"."departamento_propietarios"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_titularidad_propietario_activo"();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');

        DB::unprepared("DROP TRIGGER IF EXISTS propietarios_estado_guard ON \"{$schema}\".\"propietarios\"");
        DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_propietario_estado\"()");
        DB::unprepared("DROP TRIGGER IF EXISTS titularidades_propietario_activo_guard ON \"{$schema}\".\"departamento_propietarios\"");
        DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_titularidad_propietario_activo\"()");
    }
};
