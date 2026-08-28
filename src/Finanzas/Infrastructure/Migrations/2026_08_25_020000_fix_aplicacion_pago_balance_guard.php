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
            CREATE OR REPLACE FUNCTION "{$schema}"."validar_aplicacion_pago_balance"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE total_aplicado numeric(14,4); v_monto_recibido numeric(14,4);
            BEGIN
                SELECT COALESCE(SUM(monto_aplicado), 0) INTO total_aplicado FROM "{$schema}"."aplicaciones_pago" WHERE pago_id = NEW.pago_id;
                SELECT p.monto_recibido INTO v_monto_recibido FROM "{$schema}"."pagos" p WHERE p.id = NEW.pago_id;
                IF total_aplicado > v_monto_recibido THEN
                    RAISE EXCEPTION 'Las aplicaciones exceden el valor recibido.' USING ERRCODE = '23514';
                END IF;
                PERFORM "{$schema}"."validar_cargo_pago_balance_for_cargo"(NEW.cargo_id);
                RETURN NULL;
            END;
            \$\$;
            SQL);
    }

    public function down(): void
    {
        // The corrected guard must remain in place for existing financial records.
    }
};
