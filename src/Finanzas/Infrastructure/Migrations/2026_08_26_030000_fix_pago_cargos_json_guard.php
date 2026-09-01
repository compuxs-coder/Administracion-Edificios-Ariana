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
            CREATE OR REPLACE FUNCTION "{$schema}"."validar_pago_cargos_balance"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE total_aplicado numeric(14,4); cargo_uuid uuid;
            BEGIN
                SELECT COALESCE(SUM(monto_aplicado), 0)
                INTO total_aplicado
                FROM "{$schema}"."aplicaciones_pago"
                WHERE pago_id = NEW.id;
                IF NEW.estado = 'registrado' AND total_aplicado > NEW.monto_recibido THEN
                    RAISE EXCEPTION 'Las aplicaciones exceden el valor recibido.' USING ERRCODE = '23514';
                END IF;
                FOR cargo_uuid IN
                    SELECT DISTINCT a.cargo_id
                    FROM "{$schema}"."aplicaciones_pago" a
                    WHERE a.pago_id = NEW.id
                LOOP
                    PERFORM "{$schema}"."validar_cargo_pago_balance_for_cargo"(cargo_uuid);
                END LOOP;
                RETURN NULL;
            END;
            \$\$;
            SQL);
    }

    public function down(): void
    {
        // This PostgreSQL compatibility fix must remain for existing payments.
    }
};
