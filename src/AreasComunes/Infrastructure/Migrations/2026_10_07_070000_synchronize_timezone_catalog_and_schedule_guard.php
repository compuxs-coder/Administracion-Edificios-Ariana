<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $catalog = $this->table('zonas_horarias');
        $zones = array_values(array_unique([...timezone_identifiers_list(), 'UTC']));
        if (DB::connection()->getDriverName() === 'pgsql') {
            $postgresZones = DB::table('pg_timezone_names')->pluck('name')->all();
            $zones = array_values(array_intersect($zones, $postgresZones));
        }
        foreach (array_chunk($zones, 250) as $chunk) {
            DB::table($catalog)->insertOrIgnore(array_map(static fn (string $zone): array => ['nombre' => $zone], $chunk));
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');
        DB::statement("DELETE FROM \"{$schema}\".\"zonas_horarias\" zone WHERE NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = zone.nombre)");
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS edificios_timezone_guard ON "{$schema}"."edificios";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_building_timezone"();

            CREATE OR REPLACE FUNCTION "{$schema}"."guard_horario_regla"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'Los horarios de una regla son inmutables.' USING ERRCODE = '23514';
                END IF;
                PERFORM 1 FROM "{$schema}"."reglas_espacio_comun" WHERE id = NEW.regla_id FOR UPDATE;
                IF EXISTS (
                    SELECT 1 FROM "{$schema}"."horarios_regla_espacio_comun" h
                    WHERE h.regla_id = NEW.regla_id AND h.dia_semana = NEW.dia_semana
                      AND h.hora_inicio < NEW.hora_fin AND NEW.hora_inicio < h.hora_fin
                ) THEN
                    RAISE EXCEPTION 'Los horarios de una regla no pueden solaparse.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_building_timezone"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = NEW.timezone) THEN
                    RAISE EXCEPTION 'La zona horaria del edificio no es válida.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER edificios_timezone_guard BEFORE INSERT OR UPDATE OF timezone ON "{$schema}"."edificios"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_building_timezone"();

            CREATE OR REPLACE FUNCTION "{$schema}"."guard_horario_regla"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'Los horarios de una regla son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM "{$schema}"."horarios_regla_espacio_comun" h
                    WHERE h.regla_id = NEW.regla_id AND h.dia_semana = NEW.dia_semana
                      AND h.hora_inicio < NEW.hora_fin AND NEW.hora_inicio < h.hora_fin
                ) THEN
                    RAISE EXCEPTION 'Los horarios de una regla no pueden solaparse.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
