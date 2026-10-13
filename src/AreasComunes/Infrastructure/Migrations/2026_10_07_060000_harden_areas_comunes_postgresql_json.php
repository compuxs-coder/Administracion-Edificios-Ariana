<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                ALTER COLUMN reservante_snapshot TYPE jsonb USING reservante_snapshot::jsonb,
                ALTER COLUMN regla_snapshot TYPE jsonb USING regla_snapshot::jsonb;
            ALTER TABLE "{$schema}"."reglas_espacio_comun"
                ADD CONSTRAINT reglas_espacio_valores_no_negativos_check CHECK (
                    antelacion_minima_minutos >= 0 AND buffer_antes_minutos >= 0 AND buffer_despues_minutos >= 0
                );
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."reglas_espacio_comun"
                DROP CONSTRAINT IF EXISTS reglas_espacio_valores_no_negativos_check;
            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                ALTER COLUMN reservante_snapshot TYPE json USING reservante_snapshot::json,
                ALTER COLUMN regla_snapshot TYPE json USING regla_snapshot::json;

            CREATE OR REPLACE FUNCTION "{$schema}"."guard_reserva_espacio"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las reservas de espacios comunes no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'INSERT' AND (NEW.estado NOT IN ('pendiente', 'confirmada') OR NEW.resuelta_por_user_id IS NOT NULL OR NEW.resuelta_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'El estado inicial de la reserva no es válido.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                       OR NEW.espacio_comun_id IS DISTINCT FROM OLD.espacio_comun_id OR NEW.regla_id IS DISTINCT FROM OLD.regla_id
                       OR NEW.ocupacion_id IS DISTINCT FROM OLD.ocupacion_id OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                       OR NEW.residente_id IS DISTINCT FROM OLD.residente_id OR NEW.reservante_snapshot::jsonb IS DISTINCT FROM OLD.reservante_snapshot::jsonb
                       OR NEW.regla_snapshot::jsonb IS DISTINCT FROM OLD.regla_snapshot::jsonb OR NEW.inicio_at IS DISTINCT FROM OLD.inicio_at
                       OR NEW.fin_at IS DISTINCT FROM OLD.fin_at OR NEW.bloqueo_inicio_at IS DISTINCT FROM OLD.bloqueo_inicio_at
                       OR NEW.bloqueo_fin_at IS DISTINCT FROM OLD.bloqueo_fin_at OR NEW.cantidad_asistentes IS DISTINCT FROM OLD.cantidad_asistentes
                       OR NEW.observaciones IS DISTINCT FROM OLD.observaciones OR NEW.creada_por_user_id IS DISTINCT FROM OLD.creada_por_user_id
                       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Los datos de origen de la reserva son inmutables.' USING ERRCODE = '23514';
                    END IF;
                    IF NOT ((OLD.estado = 'pendiente' AND NEW.estado IN ('confirmada', 'rechazada', 'cancelada'))
                            OR (OLD.estado = 'confirmada' AND NEW.estado = 'cancelada')) THEN
                        RAISE EXCEPTION 'La transición de la reserva no está permitida.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.resuelta_por_user_id IS NULL OR NEW.resuelta_at IS NULL
                       OR (OLD.resuelta_at IS NOT NULL AND NEW.resuelta_at <= OLD.resuelta_at) THEN
                        RAISE EXCEPTION 'La transición de la reserva requiere actor y fecha.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.estado = 'confirmada' THEN
                    PERFORM 1 FROM "{$schema}"."espacios_comunes" WHERE id = NEW.espacio_comun_id FOR UPDATE;
                    IF EXISTS (
                        SELECT 1 FROM "{$schema}"."reservas_espacio_comun" r
                        WHERE r.espacio_comun_id = NEW.espacio_comun_id AND r.id <> NEW.id AND r.estado = 'confirmada'
                          AND r.bloqueo_inicio_at < NEW.bloqueo_fin_at AND NEW.bloqueo_inicio_at < r.bloqueo_fin_at
                    ) THEN
                        RAISE EXCEPTION 'El espacio ya está reservado en ese intervalo.' USING ERRCODE = '23P01';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }
};
