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
            CREATE OR REPLACE FUNCTION "{$schema}"."guard_tarifa_alcance_completo"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                target_tarifa uuid;
                target_alcance text;
            BEGIN
                IF TG_TABLE_NAME = 'tarifas_concepto' THEN
                    target_tarifa := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
                ELSE
                    target_tarifa := CASE WHEN TG_OP = 'DELETE' THEN OLD.tarifa_id ELSE NEW.tarifa_id END;
                END IF;

                SELECT alcance INTO target_alcance
                FROM "{$schema}"."tarifas_concepto"
                WHERE id = target_tarifa;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                IF target_alcance = 'departamentos_especificos' AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."tarifa_departamentos" WHERE tarifa_id = target_tarifa
                ) THEN
                    RAISE EXCEPTION 'El alcance por departamentos requiere al menos un departamento.' USING ERRCODE = '23514';
                END IF;

                IF target_alcance = 'todo_el_edificio' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."tarifa_departamentos" WHERE tarifa_id = target_tarifa
                ) THEN
                    RAISE EXCEPTION 'El alcance para todo el edificio no admite departamentos específicos.' USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            \$\$;
            SQL);
    }

    public function down(): void
    {
        // Keep the corrected guard when rolling back this non-destructive repair migration.
    }
};
