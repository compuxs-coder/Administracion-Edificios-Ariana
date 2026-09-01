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

        $this->replaceGuard((string) config('database.application_schema'));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceGuard((string) config('database.application_schema'));
    }

    private function replaceGuard(string $schema): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION "{$schema}"."guard_recibo_pago_integridad"()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE pago record;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los recibos no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO pago FROM "{$schema}"."pagos" WHERE id = NEW.pago_id FOR KEY SHARE;
                IF TG_OP = 'INSERT' THEN
                    IF pago.id IS NULL
                       OR NEW.fecha_pago_snapshot <> pago.fecha_pago
                       OR NEW.monto_recibido_snapshot <> pago.monto_recibido
                       OR NEW.forma_pago_snapshot <> pago.forma_pago::text
                       OR NEW.referencia_snapshot IS DISTINCT FROM pago.referencia
                       OR NEW.emitido_por IS DISTINCT FROM pago.registrado_por THEN
                        RAISE EXCEPTION 'El recibo debe corresponder a los datos inmutables del pago.' USING ERRCODE = '23514';
                    END IF;
                    IF (pago.estado = 'registrado' AND NEW.estado <> 'emitido')
                       OR (pago.estado = 'anulado' AND (NEW.estado <> 'anulado'
                           OR NEW.anulado_por IS DISTINCT FROM pago.anulado_por
                           OR NEW.anulado_at IS DISTINCT FROM pago.anulado_at
                           OR NEW.motivo_anulacion IS DISTINCT FROM pago.motivo_anulacion)) THEN
                        RAISE EXCEPTION 'El estado del recibo debe corresponder al pago.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                   OR NEW.pago_id IS DISTINCT FROM OLD.pago_id
                   OR NEW.numero IS DISTINCT FROM OLD.numero
                   OR NEW.fecha_pago_snapshot IS DISTINCT FROM OLD.fecha_pago_snapshot
                   OR NEW.edificio_nombre_snapshot IS DISTINCT FROM OLD.edificio_nombre_snapshot
                   OR NEW.departamento_codigo_snapshot IS DISTINCT FROM OLD.departamento_codigo_snapshot
                   OR NEW.departamento_nombre_snapshot IS DISTINCT FROM OLD.departamento_nombre_snapshot
                   OR NEW.titulares_snapshot::jsonb IS DISTINCT FROM OLD.titulares_snapshot::jsonb
                   OR NEW.monto_recibido_snapshot IS DISTINCT FROM OLD.monto_recibido_snapshot
                   OR NEW.forma_pago_snapshot IS DISTINCT FROM OLD.forma_pago_snapshot
                   OR NEW.referencia_snapshot IS DISTINCT FROM OLD.referencia_snapshot
                   OR NEW.aplicaciones_snapshot::jsonb IS DISTINCT FROM OLD.aplicaciones_snapshot::jsonb
                   OR NEW.emitido_por IS DISTINCT FROM OLD.emitido_por
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Los datos de un recibo son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado <> 'emitido'
                   OR NEW.estado <> 'anulado'
                   OR pago.estado <> 'anulado'
                   OR NEW.anulado_por IS DISTINCT FROM pago.anulado_por
                   OR NEW.anulado_at IS DISTINCT FROM pago.anulado_at
                   OR NEW.motivo_anulacion IS DISTINCT FROM pago.motivo_anulacion THEN
                    RAISE EXCEPTION 'La anulación del recibo debe corresponder a la del pago.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }
};
