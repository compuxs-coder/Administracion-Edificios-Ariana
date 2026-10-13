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
            ALTER TABLE "{$schema}"."espacios_comunes"
                ADD CONSTRAINT espacios_comunes_codigo_check CHECK (codigo = upper(btrim(codigo)) AND btrim(codigo) <> ''),
                ADD CONSTRAINT espacios_comunes_texto_check CHECK (btrim(nombre) <> '' AND capacidad BETWEEN 1 AND 9999),
                ADD CONSTRAINT espacios_comunes_ubicacion_check CHECK (num_nonnulls(torre_id, piso_id) <= 1 AND (num_nonnulls(torre_id, piso_id) = 1 OR COALESCE(btrim(ubicacion_detalle), '') <> ''));
            ALTER TABLE "{$schema}"."reglas_espacio_comun"
                ADD CONSTRAINT reglas_espacio_vigencia_check CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde),
                ADD CONSTRAINT reglas_espacio_duracion_check CHECK (duracion_minima_minutos BETWEEN 1 AND 1440 AND duracion_maxima_minutos BETWEEN duracion_minima_minutos AND 1440),
                ADD CONSTRAINT reglas_espacio_anticipacion_check CHECK (antelacion_minima_minutos <= 43200 AND antelacion_maxima_dias BETWEEN 1 AND 365 AND antelacion_minima_minutos <= antelacion_maxima_dias * 1440),
                ADD CONSTRAINT reglas_espacio_buffer_check CHECK (buffer_antes_minutos <= 1440 AND buffer_despues_minutos <= 1440),
                ADD CONSTRAINT reglas_espacio_aforo_check CHECK (aforo_maximo BETWEEN 1 AND 9999);
            ALTER TABLE "{$schema}"."horarios_regla_espacio_comun"
                ADD CONSTRAINT horarios_regla_dia_check CHECK (dia_semana BETWEEN 1 AND 7),
                ADD CONSTRAINT horarios_regla_horas_check CHECK (hora_fin > hora_inicio);
            ALTER TABLE "{$schema}"."reservas_espacio_comun"
                ADD CONSTRAINT reservas_espacio_intervalo_check CHECK (fin_at > inicio_at AND bloqueo_inicio_at <= inicio_at AND bloqueo_fin_at >= fin_at),
                ADD CONSTRAINT reservas_espacio_asistentes_check CHECK (cantidad_asistentes BETWEEN 1 AND 9999),
                ADD CONSTRAINT reservas_espacio_estado_check CHECK (
                    (estado = 'pendiente' AND resuelta_por_user_id IS NULL AND resuelta_at IS NULL AND motivo_resolucion IS NULL)
                    OR (estado = 'confirmada' AND motivo_resolucion IS NULL AND ((resuelta_por_user_id IS NULL AND resuelta_at IS NULL) OR (resuelta_por_user_id IS NOT NULL AND resuelta_at IS NOT NULL)))
                    OR (estado IN ('rechazada', 'cancelada') AND resuelta_por_user_id IS NOT NULL AND resuelta_at IS NOT NULL AND COALESCE(btrim(motivo_resolucion), '') <> '')
                );

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

            CREATE FUNCTION "{$schema}"."guard_espacio_comun"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'activo' OR NEW.estado_actualizado_por IS NOT NULL OR NEW.estado_actualizado_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Los espacios comunes deben crearse activos.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los espacios comunes no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.creado_por_user_id IS DISTINCT FROM OLD.creado_por_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'La identidad y el origen del espacio son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF NEW.estado IS DISTINCT FROM OLD.estado THEN
                    IF NEW.estado_actualizado_por IS NULL OR NEW.estado_actualizado_at IS NULL
                       OR (OLD.estado_actualizado_at IS NOT NULL AND NEW.estado_actualizado_at <= OLD.estado_actualizado_at) THEN
                        RAISE EXCEPTION 'Cada transición del espacio requiere actor y fecha.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.estado = 'inactivo' AND EXISTS (
                        SELECT 1 FROM "{$schema}"."reservas_espacio_comun"
                        WHERE espacio_comun_id = OLD.id AND estado = 'confirmada' AND fin_at > CURRENT_TIMESTAMP
                    ) THEN
                        RAISE EXCEPTION 'No se puede inactivar un espacio con reservas futuras confirmadas.' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.estado_actualizado_por IS DISTINCT FROM OLD.estado_actualizado_por OR NEW.estado_actualizado_at IS DISTINCT FROM OLD.estado_actualizado_at THEN
                    RAISE EXCEPTION 'La auditoría de estado sólo cambia durante una transición.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER espacios_comunes_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."espacios_comunes"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_espacio_comun"();

            CREATE FUNCTION "{$schema}"."guard_regla_espacio"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE capacidad_espacio integer;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las reglas de espacios comunes no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND (
                    NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                    OR NEW.espacio_comun_id IS DISTINCT FROM OLD.espacio_comun_id OR NEW.vigente_desde IS DISTINCT FROM OLD.vigente_desde
                    OR OLD.vigente_hasta IS NOT NULL OR NEW.vigente_hasta IS NULL
                    OR NEW.requiere_aprobacion IS DISTINCT FROM OLD.requiere_aprobacion
                    OR NEW.duracion_minima_minutos IS DISTINCT FROM OLD.duracion_minima_minutos
                    OR NEW.duracion_maxima_minutos IS DISTINCT FROM OLD.duracion_maxima_minutos
                    OR NEW.antelacion_minima_minutos IS DISTINCT FROM OLD.antelacion_minima_minutos
                    OR NEW.antelacion_maxima_dias IS DISTINCT FROM OLD.antelacion_maxima_dias
                    OR NEW.buffer_antes_minutos IS DISTINCT FROM OLD.buffer_antes_minutos
                    OR NEW.buffer_despues_minutos IS DISTINCT FROM OLD.buffer_despues_minutos
                    OR NEW.aforo_maximo IS DISTINCT FROM OLD.aforo_maximo OR NEW.reglamento IS DISTINCT FROM OLD.reglamento
                    OR NEW.creada_por_user_id IS DISTINCT FROM OLD.creada_por_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Las versiones de reglas son inmutables salvo su cierre de vigencia.' USING ERRCODE = '23514';
                END IF;
                SELECT capacidad INTO capacidad_espacio FROM "{$schema}"."espacios_comunes"
                WHERE id = NEW.espacio_comun_id AND edificio_id = NEW.edificio_id FOR UPDATE;
                IF capacidad_espacio IS NULL OR NEW.aforo_maximo > capacidad_espacio THEN
                    RAISE EXCEPTION 'El aforo de la regla excede la capacidad del espacio.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM "{$schema}"."reglas_espacio_comun" r
                    WHERE r.espacio_comun_id = NEW.espacio_comun_id AND r.id <> NEW.id
                      AND r.vigente_desde < COALESCE(NEW.vigente_hasta, 'infinity'::date)
                      AND NEW.vigente_desde < COALESCE(r.vigente_hasta, 'infinity'::date)
                ) THEN
                    RAISE EXCEPTION 'Las vigencias de reglas no pueden solaparse.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER reglas_espacio_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."reglas_espacio_comun"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_regla_espacio"();

            CREATE FUNCTION "{$schema}"."guard_horario_regla"()
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
            CREATE TRIGGER horarios_regla_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."horarios_regla_espacio_comun"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_horario_regla"();

            CREATE FUNCTION "{$schema}"."guard_reserva_espacio"()
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
            CREATE TRIGGER reservas_espacio_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."reservas_espacio_comun"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_reserva_espacio"();

            CREATE FUNCTION "{$schema}"."prevent_bitacora_areas_mutation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'La bitácora de áreas comunes es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER bitacora_areas_guard BEFORE UPDATE OR DELETE ON "{$schema}"."bitacora_areas_comunes"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_bitacora_areas_mutation"();
            SQL);
    }

    public function down(): void
    {
        foreach (['bitacora_areas_comunes', 'reservas_espacio_comun', 'horarios_regla_espacio_comun', 'reglas_espacio_comun', 'espacios_comunes'] as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se pueden retirar las protecciones de Áreas comunes mientras exista historial.');
            }
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            foreach ($this->sqliteTriggers() as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS bitacora_areas_guard ON "{$schema}"."bitacora_areas_comunes";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_bitacora_areas_mutation"();
            DROP TRIGGER IF EXISTS reservas_espacio_guard ON "{$schema}"."reservas_espacio_comun";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_reserva_espacio"();
            DROP TRIGGER IF EXISTS horarios_regla_guard ON "{$schema}"."horarios_regla_espacio_comun";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_horario_regla"();
            DROP TRIGGER IF EXISTS reglas_espacio_guard ON "{$schema}"."reglas_espacio_comun";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_regla_espacio"();
            DROP TRIGGER IF EXISTS espacios_comunes_guard ON "{$schema}"."espacios_comunes";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_espacio_comun"();
            DROP TRIGGER IF EXISTS edificios_timezone_guard ON "{$schema}"."edificios";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_building_timezone"();
            ALTER TABLE "{$schema}"."reservas_espacio_comun" DROP CONSTRAINT IF EXISTS reservas_espacio_estado_check, DROP CONSTRAINT IF EXISTS reservas_espacio_asistentes_check, DROP CONSTRAINT IF EXISTS reservas_espacio_intervalo_check;
            ALTER TABLE "{$schema}"."horarios_regla_espacio_comun" DROP CONSTRAINT IF EXISTS horarios_regla_horas_check, DROP CONSTRAINT IF EXISTS horarios_regla_dia_check;
            ALTER TABLE "{$schema}"."reglas_espacio_comun" DROP CONSTRAINT IF EXISTS reglas_espacio_aforo_check, DROP CONSTRAINT IF EXISTS reglas_espacio_buffer_check, DROP CONSTRAINT IF EXISTS reglas_espacio_anticipacion_check, DROP CONSTRAINT IF EXISTS reglas_espacio_duracion_check, DROP CONSTRAINT IF EXISTS reglas_espacio_vigencia_check;
            ALTER TABLE "{$schema}"."espacios_comunes" DROP CONSTRAINT IF EXISTS espacios_comunes_ubicacion_check, DROP CONSTRAINT IF EXISTS espacios_comunes_texto_check, DROP CONSTRAINT IF EXISTS espacios_comunes_codigo_check;
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER espacios_comunes_shape_insert_guard BEFORE INSERT ON espacios_comunes
            WHEN NEW.codigo <> upper(trim(NEW.codigo)) OR trim(NEW.codigo) = '' OR trim(NEW.nombre) = '' OR NEW.capacidad < 1 OR NEW.capacidad > 9999
              OR ((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL)) > 1
              OR (((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL)) = 0 AND trim(COALESCE(NEW.ubicacion_detalle, '')) = '')
              OR NEW.estado <> 'activo' OR NEW.estado_actualizado_por IS NOT NULL OR NEW.estado_actualizado_at IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'El espacio común tiene una forma inválida.'); END;
            CREATE TRIGGER espacios_comunes_shape_update_guard BEFORE UPDATE ON espacios_comunes
            WHEN NEW.codigo <> upper(trim(NEW.codigo)) OR trim(NEW.codigo) = '' OR trim(NEW.nombre) = '' OR NEW.capacidad < 1 OR NEW.capacidad > 9999
              OR ((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL)) > 1
              OR (((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL)) = 0 AND trim(COALESCE(NEW.ubicacion_detalle, '')) = '')
            BEGIN SELECT RAISE(ABORT, 'El espacio común tiene una forma inválida.'); END;
            CREATE TRIGGER espacios_comunes_history_guard BEFORE UPDATE ON espacios_comunes
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.creado_por_user_id IS NOT OLD.creado_por_user_id OR NEW.created_at IS NOT OLD.created_at
              OR (NEW.estado IS NOT OLD.estado AND (NEW.estado_actualizado_por IS NULL OR NEW.estado_actualizado_at IS NULL OR (OLD.estado_actualizado_at IS NOT NULL AND NEW.estado_actualizado_at <= OLD.estado_actualizado_at)))
              OR (NEW.estado IS OLD.estado AND (NEW.estado_actualizado_por IS NOT OLD.estado_actualizado_por OR NEW.estado_actualizado_at IS NOT OLD.estado_actualizado_at))
              OR (NEW.estado = 'inactivo' AND OLD.estado <> 'inactivo' AND EXISTS (SELECT 1 FROM reservas_espacio_comun WHERE espacio_comun_id = OLD.id AND estado = 'confirmada' AND fin_at > CURRENT_TIMESTAMP))
            BEGIN SELECT RAISE(ABORT, 'La identidad, auditoría o estado del espacio no admite el cambio.'); END;
            CREATE TRIGGER espacios_comunes_delete_guard BEFORE DELETE ON espacios_comunes
            BEGIN SELECT RAISE(ABORT, 'Los espacios comunes no pueden eliminarse.'); END;

            CREATE TRIGGER reglas_espacio_shape_insert_guard BEFORE INSERT ON reglas_espacio_comun
            WHEN (NEW.vigente_hasta IS NOT NULL AND NEW.vigente_hasta <= NEW.vigente_desde)
              OR NEW.duracion_minima_minutos < 1 OR NEW.duracion_maxima_minutos < NEW.duracion_minima_minutos OR NEW.duracion_maxima_minutos > 1440
              OR NEW.antelacion_minima_minutos < 0 OR NEW.antelacion_minima_minutos > 43200 OR NEW.antelacion_maxima_dias < 1 OR NEW.antelacion_maxima_dias > 365
              OR NEW.antelacion_minima_minutos > NEW.antelacion_maxima_dias * 1440 OR NEW.buffer_antes_minutos < 0 OR NEW.buffer_antes_minutos > 1440
              OR NEW.buffer_despues_minutos < 0 OR NEW.buffer_despues_minutos > 1440 OR NEW.aforo_maximo < 1
              OR NEW.aforo_maximo > (SELECT capacidad FROM espacios_comunes WHERE id = NEW.espacio_comun_id AND edificio_id = NEW.edificio_id)
              OR EXISTS (SELECT 1 FROM reglas_espacio_comun r WHERE r.espacio_comun_id = NEW.espacio_comun_id AND r.id <> NEW.id
                AND r.vigente_desde < COALESCE(NEW.vigente_hasta, '9999-12-31') AND NEW.vigente_desde < COALESCE(r.vigente_hasta, '9999-12-31'))
            BEGIN SELECT RAISE(ABORT, 'La regla del espacio tiene una forma o vigencia inválida.'); END;
            CREATE TRIGGER reglas_espacio_history_guard BEFORE UPDATE ON reglas_espacio_comun
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.espacio_comun_id IS NOT OLD.espacio_comun_id OR NEW.vigente_desde IS NOT OLD.vigente_desde
              OR OLD.vigente_hasta IS NOT NULL OR NEW.vigente_hasta IS NULL OR NEW.requiere_aprobacion IS NOT OLD.requiere_aprobacion
              OR NEW.duracion_minima_minutos IS NOT OLD.duracion_minima_minutos OR NEW.duracion_maxima_minutos IS NOT OLD.duracion_maxima_minutos
              OR NEW.antelacion_minima_minutos IS NOT OLD.antelacion_minima_minutos OR NEW.antelacion_maxima_dias IS NOT OLD.antelacion_maxima_dias
              OR NEW.buffer_antes_minutos IS NOT OLD.buffer_antes_minutos OR NEW.buffer_despues_minutos IS NOT OLD.buffer_despues_minutos
              OR NEW.aforo_maximo IS NOT OLD.aforo_maximo OR NEW.reglamento IS NOT OLD.reglamento OR NEW.creada_por_user_id IS NOT OLD.creada_por_user_id OR NEW.created_at IS NOT OLD.created_at
              OR NEW.vigente_hasta <= NEW.vigente_desde
            BEGIN SELECT RAISE(ABORT, 'Las versiones de reglas son inmutables salvo su cierre de vigencia.'); END;
            CREATE TRIGGER reglas_espacio_delete_guard BEFORE DELETE ON reglas_espacio_comun
            BEGIN SELECT RAISE(ABORT, 'Las reglas de espacios comunes no pueden eliminarse.'); END;

            CREATE TRIGGER horarios_regla_shape_guard BEFORE INSERT ON horarios_regla_espacio_comun
            WHEN NEW.dia_semana < 1 OR NEW.dia_semana > 7 OR NEW.hora_fin <= NEW.hora_inicio
              OR EXISTS (SELECT 1 FROM horarios_regla_espacio_comun h WHERE h.regla_id = NEW.regla_id AND h.dia_semana = NEW.dia_semana AND h.hora_inicio < NEW.hora_fin AND NEW.hora_inicio < h.hora_fin)
            BEGIN SELECT RAISE(ABORT, 'El horario de la regla no es válido.'); END;
            CREATE TRIGGER horarios_regla_update_guard BEFORE UPDATE ON horarios_regla_espacio_comun
            BEGIN SELECT RAISE(ABORT, 'Los horarios de una regla son inmutables.'); END;
            CREATE TRIGGER horarios_regla_delete_guard BEFORE DELETE ON horarios_regla_espacio_comun
            BEGIN SELECT RAISE(ABORT, 'Los horarios de una regla son inmutables.'); END;

            CREATE TRIGGER reservas_espacio_shape_insert_guard BEFORE INSERT ON reservas_espacio_comun
            WHEN NEW.fin_at <= NEW.inicio_at OR NEW.bloqueo_inicio_at > NEW.inicio_at OR NEW.bloqueo_fin_at < NEW.fin_at OR NEW.cantidad_asistentes < 1 OR NEW.cantidad_asistentes > 9999
              OR NEW.estado NOT IN ('pendiente', 'confirmada') OR NEW.resuelta_por_user_id IS NOT NULL OR NEW.resuelta_at IS NOT NULL OR NEW.motivo_resolucion IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'La reserva tiene una forma inicial inválida.'); END;
            CREATE TRIGGER reservas_espacio_conflict_insert_guard BEFORE INSERT ON reservas_espacio_comun
            WHEN NEW.estado = 'confirmada' AND EXISTS (SELECT 1 FROM reservas_espacio_comun r WHERE r.espacio_comun_id = NEW.espacio_comun_id AND r.estado = 'confirmada' AND r.bloqueo_inicio_at < NEW.bloqueo_fin_at AND NEW.bloqueo_inicio_at < r.bloqueo_fin_at)
            BEGIN SELECT RAISE(ABORT, 'El espacio ya está reservado en ese intervalo.'); END;
            CREATE TRIGGER reservas_espacio_history_guard BEFORE UPDATE ON reservas_espacio_comun
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.espacio_comun_id IS NOT OLD.espacio_comun_id OR NEW.regla_id IS NOT OLD.regla_id
              OR NEW.ocupacion_id IS NOT OLD.ocupacion_id OR NEW.departamento_id IS NOT OLD.departamento_id OR NEW.residente_id IS NOT OLD.residente_id
              OR NEW.reservante_snapshot IS NOT OLD.reservante_snapshot OR NEW.regla_snapshot IS NOT OLD.regla_snapshot OR NEW.inicio_at IS NOT OLD.inicio_at OR NEW.fin_at IS NOT OLD.fin_at
              OR NEW.bloqueo_inicio_at IS NOT OLD.bloqueo_inicio_at OR NEW.bloqueo_fin_at IS NOT OLD.bloqueo_fin_at OR NEW.cantidad_asistentes IS NOT OLD.cantidad_asistentes
              OR NEW.observaciones IS NOT OLD.observaciones OR NEW.creada_por_user_id IS NOT OLD.creada_por_user_id OR NEW.created_at IS NOT OLD.created_at
              OR NOT ((OLD.estado = 'pendiente' AND NEW.estado IN ('confirmada', 'rechazada', 'cancelada')) OR (OLD.estado = 'confirmada' AND NEW.estado = 'cancelada'))
              OR NEW.resuelta_por_user_id IS NULL OR NEW.resuelta_at IS NULL
              OR (OLD.resuelta_at IS NOT NULL AND NEW.resuelta_at <= OLD.resuelta_at)
              OR (NEW.estado = 'confirmada' AND NEW.motivo_resolucion IS NOT NULL)
              OR (NEW.estado IN ('rechazada', 'cancelada') AND trim(COALESCE(NEW.motivo_resolucion, '')) = '')
            BEGIN SELECT RAISE(ABORT, 'La transición o historia de la reserva no es válida.'); END;
            CREATE TRIGGER reservas_espacio_conflict_update_guard BEFORE UPDATE ON reservas_espacio_comun
            WHEN NEW.estado = 'confirmada' AND OLD.estado <> 'confirmada' AND EXISTS (SELECT 1 FROM reservas_espacio_comun r WHERE r.espacio_comun_id = NEW.espacio_comun_id AND r.id <> NEW.id AND r.estado = 'confirmada' AND r.bloqueo_inicio_at < NEW.bloqueo_fin_at AND NEW.bloqueo_inicio_at < r.bloqueo_fin_at)
            BEGIN SELECT RAISE(ABORT, 'El espacio ya está reservado en ese intervalo.'); END;
            CREATE TRIGGER reservas_espacio_delete_guard BEFORE DELETE ON reservas_espacio_comun
            BEGIN SELECT RAISE(ABORT, 'Las reservas de espacios comunes no pueden eliminarse.'); END;

            CREATE TRIGGER bitacora_areas_update_guard BEFORE UPDATE ON bitacora_areas_comunes
            BEGIN SELECT RAISE(ABORT, 'La bitácora de áreas comunes es inmutable.'); END;
            CREATE TRIGGER bitacora_areas_delete_guard BEFORE DELETE ON bitacora_areas_comunes
            BEGIN SELECT RAISE(ABORT, 'La bitácora de áreas comunes es inmutable.'); END;
            SQL);
    }

    /** @return list<string> */
    private function sqliteTriggers(): array
    {
        return ['espacios_comunes_shape_insert_guard', 'espacios_comunes_shape_update_guard', 'espacios_comunes_history_guard', 'espacios_comunes_delete_guard', 'reglas_espacio_shape_insert_guard', 'reglas_espacio_history_guard', 'reglas_espacio_delete_guard', 'horarios_regla_shape_guard', 'horarios_regla_update_guard', 'horarios_regla_delete_guard', 'reservas_espacio_shape_insert_guard', 'reservas_espacio_conflict_insert_guard', 'reservas_espacio_history_guard', 'reservas_espacio_conflict_update_guard', 'reservas_espacio_delete_guard', 'bitacora_areas_update_guard', 'bitacora_areas_delete_guard'];
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
