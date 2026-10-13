<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->createSqliteGuards();

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."reglas_espacio_comun" rule
                    JOIN "{$schema}"."edificios" building ON building.id = rule.edificio_id
                    WHERE rule.timezone IS DISTINCT FROM building.timezone
                ) THEN
                    RAISE EXCEPTION 'Existen reglas cuya zona horaria no coincide con su edificio.';
                END IF;
            END;
            \$\$;

            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                ADD CONSTRAINT reservas_reservante_snapshot_shape_check CHECK (
                    jsonb_typeof(reservante_snapshot) = 'object'
                    AND reservante_snapshot ?& ARRAY['id', 'edificioId', 'departamentoId', 'residenteId', 'nombre', 'tipoIdentificacion', 'identificacion', 'tipoOcupacion', 'departamentoCodigo', 'departamentoNombre', 'fechaInicio', 'fechaFin']
                    AND jsonb_typeof(reservante_snapshot->'id') = 'string'
                    AND jsonb_typeof(reservante_snapshot->'nombre') = 'string'
                ) NOT VALID,
                ADD CONSTRAINT reservas_regla_snapshot_shape_check CHECK (
                    jsonb_typeof(regla_snapshot) = 'object'
                    AND regla_snapshot ?& ARRAY['vigenteDesde', 'timezone', 'requiereAprobacion', 'duracionMinimaMinutos', 'duracionMaximaMinutos', 'antelacionMinimaMinutos', 'antelacionMaximaDias', 'bufferAntesMinutos', 'bufferDespuesMinutos', 'aforoMaximo', 'reglamento']
                    AND jsonb_typeof(regla_snapshot->'timezone') = 'string'
                ) NOT VALID;
            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                VALIDATE CONSTRAINT reservas_reservante_snapshot_shape_check;
            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                VALIDATE CONSTRAINT reservas_regla_snapshot_shape_check;

            DO \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM "{$schema}"."reservas_espacio_comun" reservation
                    JOIN "{$schema}"."departamento_residentes" occupation
                      ON occupation.edificio_id = reservation.edificio_id
                     AND occupation.id = reservation.ocupacion_id
                     AND occupation.departamento_id = reservation.departamento_id
                     AND occupation.residente_id = reservation.residente_id
                    JOIN "{$schema}"."reglas_espacio_comun" rule
                      ON rule.edificio_id = reservation.edificio_id
                     AND rule.id = reservation.regla_id
                    WHERE reservation.estado IN ('pendiente', 'confirmada')
                      AND reservation.fin_at > CURRENT_TIMESTAMP
                      AND (
                          occupation.estado <> 'activa'
                          OR occupation.fecha_inicio > (reservation.inicio_at AT TIME ZONE rule.timezone)::date
                          OR (occupation.fecha_fin IS NOT NULL AND occupation.fecha_fin <= (reservation.inicio_at AT TIME ZONE rule.timezone)::date)
                          OR reservation.regla_snapshot->>'timezone' IS DISTINCT FROM rule.timezone
                      )
                ) THEN
                    RAISE EXCEPTION 'Existen reservas vigentes con una ocupación o zona horaria inconsistente.';
                END IF;
            END;
            \$\$;

            CREATE FUNCTION "{$schema}"."guard_rule_building_timezone"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE building_timezone varchar(100);
            BEGIN
                SELECT timezone INTO building_timezone
                FROM "{$schema}"."edificios"
                WHERE id = NEW.edificio_id
                FOR UPDATE;
                IF building_timezone IS NULL OR NEW.timezone IS DISTINCT FROM building_timezone THEN
                    RAISE EXCEPTION 'La zona horaria de la regla debe coincidir con la del edificio.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER reglas_building_timezone_guard BEFORE INSERT ON "{$schema}"."reglas_espacio_comun"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_rule_building_timezone"();

            CREATE FUNCTION "{$schema}"."guard_reservation_occupation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE occupation_start date;
            DECLARE occupation_end date;
            DECLARE occupation_status varchar(20);
            DECLARE rule_timezone varchar(100);
            DECLARE reservation_date date;
            BEGIN
                IF TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND NEW.estado = 'confirmada' AND OLD.estado IS DISTINCT FROM NEW.estado) THEN
                    SELECT occupation.fecha_inicio, occupation.fecha_fin, occupation.estado, rule.timezone
                    INTO occupation_start, occupation_end, occupation_status, rule_timezone
                    FROM "{$schema}"."departamento_residentes" occupation
                    JOIN "{$schema}"."reglas_espacio_comun" rule
                      ON rule.edificio_id = NEW.edificio_id AND rule.id = NEW.regla_id AND rule.espacio_comun_id = NEW.espacio_comun_id
                    WHERE occupation.edificio_id = NEW.edificio_id
                      AND occupation.id = NEW.ocupacion_id
                      AND occupation.departamento_id = NEW.departamento_id
                      AND occupation.residente_id = NEW.residente_id
                    FOR UPDATE OF occupation;
                    reservation_date := (NEW.inicio_at AT TIME ZONE rule_timezone)::date;
                    IF occupation_start IS NULL OR occupation_status <> 'activa'
                       OR occupation_start > reservation_date
                       OR (occupation_end IS NOT NULL AND occupation_end <= reservation_date) THEN
                        RAISE EXCEPTION 'La ocupación no está vigente para la fecha de la reserva.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.regla_snapshot->>'timezone' IS DISTINCT FROM rule_timezone THEN
                        RAISE EXCEPTION 'La zona horaria histórica no coincide con la regla.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER reservas_occupation_guard BEFORE INSERT OR UPDATE OF estado ON "{$schema}"."reservas_espacio_comun"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_reservation_occupation"();

            CREATE FUNCTION "{$schema}"."guard_occupation_reservations"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.fecha_fin IS NOT NULL AND EXISTS (
                    SELECT 1
                    FROM "{$schema}"."reservas_espacio_comun" reservation
                    JOIN "{$schema}"."reglas_espacio_comun" rule
                      ON rule.edificio_id = reservation.edificio_id AND rule.id = reservation.regla_id
                    WHERE reservation.edificio_id = NEW.edificio_id
                      AND reservation.ocupacion_id = NEW.id
                      AND reservation.estado IN ('pendiente', 'confirmada')
                      AND (reservation.inicio_at AT TIME ZONE rule.timezone)::date >= NEW.fecha_fin
                ) THEN
                    RAISE EXCEPTION 'La ocupación tiene reservas vigentes posteriores a su fecha final.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ocupaciones_reservas_guard BEFORE UPDATE OF fecha_fin, estado ON "{$schema}"."departamento_residentes"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_occupation_reservations"();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS reservas_snapshot_shape_guard');
            DB::statement('DROP TRIGGER IF EXISTS reglas_building_timezone_guard');

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS ocupaciones_reservas_guard ON "{$schema}"."departamento_residentes";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_occupation_reservations"();
            DROP TRIGGER IF EXISTS reservas_occupation_guard ON "{$schema}"."reservas_espacio_comun";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_reservation_occupation"();
            DROP TRIGGER IF EXISTS reglas_building_timezone_guard ON "{$schema}"."reglas_espacio_comun";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_rule_building_timezone"();
            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                DROP CONSTRAINT IF EXISTS reservas_regla_snapshot_shape_check,
                DROP CONSTRAINT IF EXISTS reservas_reservante_snapshot_shape_check;
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reglas_building_timezone_guard BEFORE INSERT ON reglas_espacio_comun
            WHEN NOT EXISTS (
                SELECT 1 FROM edificios
                WHERE id = NEW.edificio_id AND timezone = NEW.timezone
            )
            BEGIN SELECT RAISE(ABORT, 'La zona horaria de la regla debe coincidir con la del edificio.'); END;

            CREATE TRIGGER reservas_snapshot_shape_guard BEFORE INSERT ON reservas_espacio_comun
            WHEN json_valid(NEW.reservante_snapshot) = 0 OR json_type(NEW.reservante_snapshot) <> 'object'
              OR json_type(NEW.reservante_snapshot, '$.id') <> 'text' OR json_type(NEW.reservante_snapshot, '$.nombre') <> 'text'
              OR json_type(NEW.reservante_snapshot, '$.edificioId') IS NULL OR json_type(NEW.reservante_snapshot, '$.departamentoId') IS NULL
              OR json_type(NEW.reservante_snapshot, '$.residenteId') IS NULL OR json_type(NEW.reservante_snapshot, '$.tipoIdentificacion') IS NULL
              OR json_type(NEW.reservante_snapshot, '$.identificacion') IS NULL OR json_type(NEW.reservante_snapshot, '$.tipoOcupacion') IS NULL
              OR json_type(NEW.reservante_snapshot, '$.departamentoCodigo') IS NULL OR json_type(NEW.reservante_snapshot, '$.departamentoNombre') IS NULL
              OR json_type(NEW.reservante_snapshot, '$.fechaInicio') IS NULL OR json_type(NEW.reservante_snapshot, '$.fechaFin') IS NULL
              OR json_valid(NEW.regla_snapshot) = 0 OR json_type(NEW.regla_snapshot) <> 'object'
              OR json_type(NEW.regla_snapshot, '$.timezone') <> 'text' OR json_type(NEW.regla_snapshot, '$.vigenteDesde') IS NULL
              OR json_type(NEW.regla_snapshot, '$.requiereAprobacion') IS NULL OR json_type(NEW.regla_snapshot, '$.duracionMinimaMinutos') IS NULL
              OR json_type(NEW.regla_snapshot, '$.duracionMaximaMinutos') IS NULL OR json_type(NEW.regla_snapshot, '$.antelacionMinimaMinutos') IS NULL
              OR json_type(NEW.regla_snapshot, '$.antelacionMaximaDias') IS NULL OR json_type(NEW.regla_snapshot, '$.bufferAntesMinutos') IS NULL
              OR json_type(NEW.regla_snapshot, '$.bufferDespuesMinutos') IS NULL OR json_type(NEW.regla_snapshot, '$.aforoMaximo') IS NULL
              OR json_type(NEW.regla_snapshot, '$.reglamento') IS NULL
            BEGIN SELECT RAISE(ABORT, 'Los snapshots de la reserva tienen una forma inválida.'); END;
            SQL);
    }
};
