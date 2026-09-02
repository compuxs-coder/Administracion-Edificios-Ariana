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

        $this->replaceGuards((string) config('database.application_schema'), 'SHARE');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceGuards((string) config('database.application_schema'), 'KEY SHARE');
    }

    private function replaceGuards(string $schema, string $lockMode): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION "{$schema}"."guard_residente_natural"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                PERFORM 1
                FROM "{$schema}"."terceros"
                WHERE id = NEW.tercero_id AND tipo_persona = 'persona_natural'
                FOR {$lockMode};

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Un residente debe corresponder a una persona natural.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;

            CREATE OR REPLACE FUNCTION "{$schema}"."guard_departamento_ocupacion"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de ocupación no puede eliminarse.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.estado = 'finalizada' THEN
                    RAISE EXCEPTION 'Una ocupación finalizada es inmutable.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' AND (
                    NEW.edificio_id IS DISTINCT FROM OLD.edificio_id OR
                    NEW.departamento_id IS DISTINCT FROM OLD.departamento_id OR
                    NEW.residente_id IS DISTINCT FROM OLD.residente_id OR
                    NEW.tipo_ocupacion IS DISTINCT FROM OLD.tipo_ocupacion OR
                    NEW.fecha_inicio IS DISTINCT FROM OLD.fecha_inicio OR
                    NEW.nombre_residente IS DISTINCT FROM OLD.nombre_residente OR
                    NEW.tipo_identificacion_snapshot IS DISTINCT FROM OLD.tipo_identificacion_snapshot OR
                    NEW.identificacion_snapshot IS DISTINCT FROM OLD.identificacion_snapshot
                ) THEN
                    RAISE EXCEPTION 'Los datos de origen de una ocupación son inmutables.' USING ERRCODE = '23514';
                END IF;

                IF NEW.estado = 'activa' THEN
                    PERFORM 1
                    FROM "{$schema}"."residentes"
                    WHERE id = NEW.residente_id AND estado = 'activo'
                    FOR {$lockMode};
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Una ocupación activa requiere un residente activo.' USING ERRCODE = '23514';
                    END IF;

                    PERFORM 1
                    FROM "{$schema}"."edificios"
                    WHERE id = NEW.edificio_id AND estado = 'activo'
                    FOR {$lockMode};
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Una ocupación activa requiere un edificio activo.' USING ERRCODE = '23514';
                    END IF;

                    PERFORM 1
                    FROM "{$schema}"."departamentos"
                    WHERE id = NEW.departamento_id
                      AND edificio_id = NEW.edificio_id
                      AND estado = 'activo'
                    FOR UPDATE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Una ocupación activa requiere un departamento activo.' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    PERFORM 1
                    FROM "{$schema}"."departamentos"
                    WHERE id = NEW.departamento_id AND edificio_id = NEW.edificio_id
                    FOR UPDATE;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."departamento_residentes" existing
                    WHERE existing.departamento_id = NEW.departamento_id
                      AND existing.residente_id = NEW.residente_id
                      AND existing.id <> NEW.id
                      AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::date)
                      AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::date)
                ) THEN
                    RAISE EXCEPTION 'La ocupación se superpone con el historial existente.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'INSERT' AND NEW.tipo_ocupacion = 'propietario_ocupante' AND NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."residentes" resident
                    JOIN "{$schema}"."propietarios" owner_profile
                      ON owner_profile.tercero_id = resident.tercero_id
                    JOIN "{$schema}"."departamento_propietarios" ownership
                      ON ownership.propietario_id = owner_profile.id
                    WHERE resident.id = NEW.residente_id
                      AND ownership.departamento_id = NEW.departamento_id
                      AND ownership.fecha_inicio <= NEW.fecha_inicio
                      AND (ownership.fecha_fin IS NULL OR ownership.fecha_fin > NEW.fecha_inicio)
                ) THEN
                    RAISE EXCEPTION 'El propietario ocupante requiere una titularidad vigente al inicio.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$;
            SQL);
    }
};
