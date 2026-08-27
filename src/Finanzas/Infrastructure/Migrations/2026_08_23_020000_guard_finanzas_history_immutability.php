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
            CREATE FUNCTION "{$schema}"."guard_concepto_cobro_configuracion"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF (NEW.tipo IS DISTINCT FROM OLD.tipo
                    OR NEW.periodicidad IS DISTINCT FROM OLD.periodicidad
                    OR NEW.forma_calculo IS DISTINCT FROM OLD.forma_calculo)
                    AND EXISTS (
                        SELECT 1
                        FROM "{$schema}"."tarifas_concepto"
                        WHERE concepto_cobro_id = OLD.id
                    ) THEN
                    RAISE EXCEPTION 'No se puede cambiar la configuración de un concepto con tarifas.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER conceptos_cobro_configuracion_guard
            BEFORE UPDATE OF tipo, periodicidad, forma_calculo
            ON "{$schema}"."conceptos_cobro"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_concepto_cobro_configuracion"();

            CREATE FUNCTION "{$schema}"."guard_tarifa_departamento_historial"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."tarifas_concepto"
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.tarifa_id ELSE NEW.tarifa_id END
                      AND fecha_fin IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'El alcance de una tarifa finalizada es inmutable.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' AND EXISTS (
                    SELECT 1
                    FROM "{$schema}"."tarifas_concepto"
                    WHERE id = OLD.tarifa_id AND fecha_fin IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'El alcance de una tarifa finalizada es inmutable.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE TRIGGER tarifa_departamentos_historial_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON "{$schema}"."tarifa_departamentos"
            FOR EACH ROW
            EXECUTE FUNCTION "{$schema}"."guard_tarifa_departamento_historial"();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');

        DB::unprepared("DROP TRIGGER IF EXISTS conceptos_cobro_configuracion_guard ON \"{$schema}\".\"conceptos_cobro\"");
        DB::unprepared("DROP TRIGGER IF EXISTS tarifa_departamentos_historial_guard ON \"{$schema}\".\"tarifa_departamentos\"");
        DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_concepto_cobro_configuracion\"()");
        DB::unprepared("DROP FUNCTION IF EXISTS \"{$schema}\".\"guard_tarifa_departamento_historial\"()");
    }
};
