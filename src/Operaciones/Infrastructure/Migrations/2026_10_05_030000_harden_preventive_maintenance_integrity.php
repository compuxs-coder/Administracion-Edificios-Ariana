<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if (! $pgsql) {
            $this->createSqliteGuards();

            return;
        }
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo"
                ADD CONSTRAINT planes_mantenimiento_codigo_check CHECK (codigo = upper(btrim(codigo)) AND btrim(codigo) <> ''),
                ADD CONSTRAINT planes_mantenimiento_texto_check CHECK (btrim(titulo) <> '' AND btrim(descripcion) <> ''),
                ADD CONSTRAINT planes_mantenimiento_recurrencia_check CHECK (intervalo_recurrencia BETWEEN 1 AND 99 AND dias_anticipacion BETWEEN 0 AND 365),
                ADD CONSTRAINT planes_mantenimiento_ubicacion_check CHECK (num_nonnulls(torre_id, piso_id, departamento_id, parqueadero_id, bodega_id) <= 1 AND (num_nonnulls(torre_id, piso_id, departamento_id, parqueadero_id, bodega_id) = 1 OR COALESCE(btrim(ubicacion_detalle), '') <> '')),
                ADD CONSTRAINT planes_mantenimiento_contrato_check CHECK (contrato_id IS NULL OR proveedor_id IS NOT NULL),
                ADD CONSTRAINT planes_mantenimiento_programacion_check CHECK ((estado = 'activo' AND fecha_ancla IS NOT NULL AND proxima_fecha_programada IS NOT NULL) OR (estado = 'inactivo' AND fecha_ancla IS NULL AND proxima_fecha_programada IS NULL AND secuencia_siguiente = 0));

            ALTER TABLE "{$schema}"."ocurrencias_mantenimiento_preventivo"
                ADD CONSTRAINT ocurrencias_mantenimiento_estado_check CHECK (
                    (estado = 'pendiente' AND motivo IS NULL AND procesada_at IS NULL)
                    OR (estado = 'generada' AND motivo IS NULL AND procesada_at IS NOT NULL)
                    OR (estado IN ('bloqueada', 'omitida') AND COALESCE(btrim(motivo), '') <> '' AND procesada_at IS NOT NULL)
                );

            ALTER TABLE "{$schema}"."bitacora_plan_mantenimiento"
                ADD CONSTRAINT bitacora_plan_actor_check CHECK ((actor_tipo = 'usuario' AND actor_user_id IS NOT NULL) OR (actor_tipo = 'sistema' AND actor_user_id IS NULL));
            ALTER TABLE "{$schema}"."ordenes_operativas"
                ADD CONSTRAINT ordenes_origen_preventivo_check CHECK (
                    (origen = 'manual' AND tipo IN ('incidencia', 'solicitud') AND ocurrencia_mantenimiento_id IS NULL AND creada_por_tipo = 'usuario' AND creada_por_user_id IS NOT NULL)
                    OR (origen = 'programacion_preventiva' AND tipo = 'mantenimiento_preventivo' AND ocurrencia_mantenimiento_id IS NOT NULL AND creada_por_tipo = 'sistema' AND creada_por_user_id IS NULL)
                );
            ALTER TABLE "{$schema}"."bitacora_orden_operativa"
                ADD CONSTRAINT bitacora_orden_actor_check CHECK ((actor_tipo = 'usuario' AND actor_user_id IS NOT NULL) OR (actor_tipo = 'sistema' AND actor_user_id IS NULL));

            CREATE FUNCTION "{$schema}"."guard_orden_preventive_origin"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.origen IS DISTINCT FROM OLD.origen
                   OR NEW.ocurrencia_mantenimiento_id IS DISTINCT FROM OLD.ocurrencia_mantenimiento_id
                   OR NEW.creada_por_tipo IS DISTINCT FROM OLD.creada_por_tipo THEN
                    RAISE EXCEPTION 'El origen preventivo de la orden es inmutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ordenes_origen_preventivo_guard BEFORE UPDATE ON "{$schema}"."ordenes_operativas"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_orden_preventive_origin"();

            CREATE FUNCTION "{$schema}"."sync_preventive_order_occurrence"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.origen = 'programacion_preventiva' THEN
                    UPDATE "{$schema}"."ocurrencias_mantenimiento_preventivo"
                    SET estado = 'generada', motivo = NULL,
                        procesada_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                    WHERE id = NEW.ocurrencia_mantenimiento_id AND edificio_id = NEW.edificio_id
                      AND estado IN ('pendiente', 'bloqueada');
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'La orden preventiva requiere una ocurrencia abierta.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ordenes_preventive_occurrence_sync AFTER INSERT ON "{$schema}"."ordenes_operativas"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."sync_preventive_order_occurrence"();

            CREATE FUNCTION "{$schema}"."guard_plan_mantenimiento_history"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'inactivo' THEN
                        RAISE EXCEPTION 'Los planes de mantenimiento deben crearse inactivos.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los planes de mantenimiento no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.creado_por_user_id IS DISTINCT FROM OLD.creado_por_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'La identidad y el origen del plan son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'activo' AND (
                    NEW.codigo IS DISTINCT FROM OLD.codigo OR NEW.titulo IS DISTINCT FROM OLD.titulo
                    OR NEW.descripcion IS DISTINCT FROM OLD.descripcion OR NEW.prioridad IS DISTINCT FROM OLD.prioridad
                    OR NEW.unidad_recurrencia IS DISTINCT FROM OLD.unidad_recurrencia
                    OR NEW.intervalo_recurrencia IS DISTINCT FROM OLD.intervalo_recurrencia
                    OR NEW.dias_anticipacion IS DISTINCT FROM OLD.dias_anticipacion
                    OR NEW.torre_id IS DISTINCT FROM OLD.torre_id OR NEW.piso_id IS DISTINCT FROM OLD.piso_id
                    OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                    OR NEW.parqueadero_id IS DISTINCT FROM OLD.parqueadero_id OR NEW.bodega_id IS DISTINCT FROM OLD.bodega_id
                    OR NEW.ubicacion_detalle IS DISTINCT FROM OLD.ubicacion_detalle
                    OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id OR NEW.contrato_id IS DISTINCT FROM OLD.contrato_id
                    OR (NEW.estado = 'activo' AND NEW.fecha_ancla IS DISTINCT FROM OLD.fecha_ancla)
                ) THEN
                    RAISE EXCEPTION 'La configuración de un plan activo es inmutable.' USING ERRCODE = '23514';
                END IF;
                IF NEW.estado IS DISTINCT FROM OLD.estado
                   AND (NEW.estado_actualizado_por IS NULL OR NEW.estado_actualizado_at IS NULL
                        OR (OLD.estado_actualizado_at IS NOT NULL AND NEW.estado_actualizado_at <= OLD.estado_actualizado_at)) THEN
                    RAISE EXCEPTION 'Cada transición del plan requiere actor y fecha.' USING ERRCODE = '23514';
                END IF;
                IF NEW.estado IS NOT DISTINCT FROM OLD.estado
                   AND (NEW.estado_actualizado_por IS DISTINCT FROM OLD.estado_actualizado_por
                        OR NEW.estado_actualizado_at IS DISTINCT FROM OLD.estado_actualizado_at) THEN
                    RAISE EXCEPTION 'La auditoría de estado sólo cambia durante una transición.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER planes_mantenimiento_history_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."planes_mantenimiento_preventivo"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_plan_mantenimiento_history"();

            CREATE FUNCTION "{$schema}"."guard_ocurrencia_mantenimiento"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'pendiente' THEN
                        RAISE EXCEPTION 'Las ocurrencias deben crearse pendientes.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las ocurrencias preventivas no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                       OR NEW.plan_id IS DISTINCT FROM OLD.plan_id OR NEW.fecha_programada IS DISTINCT FROM OLD.fecha_programada
                       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'La identidad de la ocurrencia es inmutable.' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.estado IN ('generada', 'omitida') OR NOT (
                        (OLD.estado = 'pendiente' AND NEW.estado IN ('generada', 'bloqueada', 'omitida'))
                        OR (OLD.estado = 'bloqueada' AND NEW.estado IN ('bloqueada', 'generada', 'omitida'))
                    ) THEN
                        RAISE EXCEPTION 'La transición de la ocurrencia no está permitida.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.estado = 'generada' AND NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."ordenes_operativas"
                        WHERE edificio_id = NEW.edificio_id AND ocurrencia_mantenimiento_id = NEW.id
                          AND origen = 'programacion_preventiva'
                    ) THEN
                        RAISE EXCEPTION 'Una ocurrencia generada requiere su orden preventiva.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ocurrencias_mantenimiento_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."ocurrencias_mantenimiento_preventivo"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_ocurrencia_mantenimiento"();

            CREATE FUNCTION "{$schema}"."prevent_bitacora_plan_mutation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'La bitácora del plan es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER bitacora_plan_mantenimiento_guard BEFORE UPDATE OR DELETE ON "{$schema}"."bitacora_plan_mantenimiento"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_bitacora_plan_mutation"();
            SQL);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        foreach (['bitacora_plan_mantenimiento', 'ocurrencias_mantenimiento_preventivo', 'planes_mantenimiento_preventivo'] as $historyTable) {
            if (DB::table($table($historyTable))->exists()) {
                throw new RuntimeException('No se pueden retirar las protecciones de mantenimiento preventivo mientras exista historial.');
            }
        }
        if (DB::table($table('ordenes_operativas'))->where('origen', 'programacion_preventiva')->exists()) {
            throw new RuntimeException('No se pueden retirar las protecciones mientras existan órdenes preventivas.');
        }

        if (! $pgsql) {
            foreach (['planes_mantenimiento_shape_insert_guard', 'planes_mantenimiento_shape_update_guard', 'planes_mantenimiento_history_guard', 'planes_mantenimiento_delete_guard', 'ocurrencias_mantenimiento_shape_insert_guard', 'ocurrencias_mantenimiento_shape_update_guard', 'ocurrencias_mantenimiento_history_guard', 'ocurrencias_mantenimiento_delete_guard', 'bitacora_plan_actor_guard', 'bitacora_plan_mantenimiento_guard', 'bitacora_plan_mantenimiento_delete_guard', 'ordenes_origen_preventivo_insert_guard', 'ordenes_origen_preventivo_update_guard', 'ordenes_preventive_occurrence_sync', 'bitacora_orden_actor_insert_guard', 'bitacora_orden_actor_update_guard'] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS ordenes_preventive_occurrence_sync ON "{$schema}"."ordenes_operativas";
            DROP FUNCTION IF EXISTS "{$schema}"."sync_preventive_order_occurrence"();
            DROP TRIGGER IF EXISTS ordenes_origen_preventivo_guard ON "{$schema}"."ordenes_operativas";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_orden_preventive_origin"();
            DROP TRIGGER IF EXISTS bitacora_plan_mantenimiento_guard ON "{$schema}"."bitacora_plan_mantenimiento";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_bitacora_plan_mutation"();
            DROP TRIGGER IF EXISTS ocurrencias_mantenimiento_guard ON "{$schema}"."ocurrencias_mantenimiento_preventivo";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_ocurrencia_mantenimiento"();
            DROP TRIGGER IF EXISTS planes_mantenimiento_history_guard ON "{$schema}"."planes_mantenimiento_preventivo";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_plan_mantenimiento_history"();
            ALTER TABLE "{$schema}"."bitacora_orden_operativa" DROP CONSTRAINT IF EXISTS bitacora_orden_actor_check;
            ALTER TABLE "{$schema}"."ordenes_operativas" DROP CONSTRAINT IF EXISTS ordenes_origen_preventivo_check;
            ALTER TABLE "{$schema}"."bitacora_plan_mantenimiento" DROP CONSTRAINT IF EXISTS bitacora_plan_actor_check;
            ALTER TABLE "{$schema}"."ocurrencias_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS ocurrencias_mantenimiento_estado_check;
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS planes_mantenimiento_programacion_check;
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS planes_mantenimiento_contrato_check;
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS planes_mantenimiento_ubicacion_check;
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS planes_mantenimiento_recurrencia_check;
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS planes_mantenimiento_texto_check;
            ALTER TABLE "{$schema}"."planes_mantenimiento_preventivo" DROP CONSTRAINT IF EXISTS planes_mantenimiento_codigo_check;
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER planes_mantenimiento_shape_insert_guard BEFORE INSERT ON planes_mantenimiento_preventivo
            WHEN NEW.codigo <> upper(trim(NEW.codigo)) OR trim(NEW.codigo) = '' OR trim(NEW.titulo) = '' OR trim(NEW.descripcion) = ''
              OR NEW.intervalo_recurrencia < 1 OR NEW.intervalo_recurrencia > 99 OR NEW.dias_anticipacion < 0 OR NEW.dias_anticipacion > 365
              OR ((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) > 1
              OR (((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) = 0 AND trim(COALESCE(NEW.ubicacion_detalle, '')) = '')
              OR (NEW.contrato_id IS NOT NULL AND NEW.proveedor_id IS NULL)
              OR NEW.estado <> 'inactivo'
              OR NOT ((NEW.estado = 'activo' AND NEW.fecha_ancla IS NOT NULL AND NEW.proxima_fecha_programada IS NOT NULL) OR (NEW.estado = 'inactivo' AND NEW.fecha_ancla IS NULL AND NEW.proxima_fecha_programada IS NULL AND NEW.secuencia_siguiente = 0))
            BEGIN SELECT RAISE(ABORT, 'El plan de mantenimiento tiene una forma inválida.'); END;

            CREATE TRIGGER planes_mantenimiento_shape_update_guard BEFORE UPDATE ON planes_mantenimiento_preventivo
            WHEN NEW.codigo <> upper(trim(NEW.codigo)) OR trim(NEW.codigo) = '' OR trim(NEW.titulo) = '' OR trim(NEW.descripcion) = ''
              OR NEW.intervalo_recurrencia < 1 OR NEW.intervalo_recurrencia > 99 OR NEW.dias_anticipacion < 0 OR NEW.dias_anticipacion > 365
              OR ((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) > 1
              OR (((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) = 0 AND trim(COALESCE(NEW.ubicacion_detalle, '')) = '')
              OR (NEW.contrato_id IS NOT NULL AND NEW.proveedor_id IS NULL)
              OR NOT ((NEW.estado = 'activo' AND NEW.fecha_ancla IS NOT NULL AND NEW.proxima_fecha_programada IS NOT NULL) OR (NEW.estado = 'inactivo' AND NEW.fecha_ancla IS NULL AND NEW.proxima_fecha_programada IS NULL AND NEW.secuencia_siguiente = 0))
            BEGIN SELECT RAISE(ABORT, 'El plan de mantenimiento tiene una forma inválida.'); END;

            CREATE TRIGGER planes_mantenimiento_history_guard BEFORE UPDATE ON planes_mantenimiento_preventivo
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.creado_por_user_id IS NOT OLD.creado_por_user_id OR NEW.created_at IS NOT OLD.created_at
              OR (OLD.estado = 'activo' AND (NEW.codigo IS NOT OLD.codigo OR NEW.titulo IS NOT OLD.titulo OR NEW.descripcion IS NOT OLD.descripcion
                OR NEW.prioridad IS NOT OLD.prioridad OR NEW.unidad_recurrencia IS NOT OLD.unidad_recurrencia
                OR NEW.intervalo_recurrencia IS NOT OLD.intervalo_recurrencia OR NEW.dias_anticipacion IS NOT OLD.dias_anticipacion
                OR NEW.torre_id IS NOT OLD.torre_id OR NEW.piso_id IS NOT OLD.piso_id OR NEW.departamento_id IS NOT OLD.departamento_id
                OR NEW.parqueadero_id IS NOT OLD.parqueadero_id OR NEW.bodega_id IS NOT OLD.bodega_id
                OR NEW.ubicacion_detalle IS NOT OLD.ubicacion_detalle OR NEW.proveedor_id IS NOT OLD.proveedor_id OR NEW.contrato_id IS NOT OLD.contrato_id
                OR (NEW.estado = 'activo' AND NEW.fecha_ancla IS NOT OLD.fecha_ancla)))
              OR (NEW.estado IS NOT OLD.estado AND (NEW.estado_actualizado_por IS NULL OR NEW.estado_actualizado_at IS NULL
                OR (OLD.estado_actualizado_at IS NOT NULL AND NEW.estado_actualizado_at <= OLD.estado_actualizado_at)))
              OR (NEW.estado IS OLD.estado AND (NEW.estado_actualizado_por IS NOT OLD.estado_actualizado_por
                OR NEW.estado_actualizado_at IS NOT OLD.estado_actualizado_at))
            BEGIN SELECT RAISE(ABORT, 'La identidad y el origen del plan son inmutables.'); END;
            CREATE TRIGGER planes_mantenimiento_delete_guard BEFORE DELETE ON planes_mantenimiento_preventivo
            BEGIN SELECT RAISE(ABORT, 'Los planes de mantenimiento no pueden eliminarse.'); END;

            CREATE TRIGGER ocurrencias_mantenimiento_shape_insert_guard BEFORE INSERT ON ocurrencias_mantenimiento_preventivo
            WHEN NOT (NEW.estado = 'pendiente' AND NEW.motivo IS NULL AND NEW.procesada_at IS NULL)
            BEGIN SELECT RAISE(ABORT, 'La ocurrencia preventiva tiene una forma inválida.'); END;
            CREATE TRIGGER ocurrencias_mantenimiento_shape_update_guard BEFORE UPDATE ON ocurrencias_mantenimiento_preventivo
            WHEN NOT ((NEW.estado = 'pendiente' AND NEW.motivo IS NULL AND NEW.procesada_at IS NULL)
              OR (NEW.estado = 'generada' AND NEW.motivo IS NULL AND NEW.procesada_at IS NOT NULL)
              OR (NEW.estado IN ('bloqueada', 'omitida') AND trim(COALESCE(NEW.motivo, '')) <> '' AND NEW.procesada_at IS NOT NULL))
            BEGIN SELECT RAISE(ABORT, 'La ocurrencia preventiva tiene una forma inválida.'); END;
            CREATE TRIGGER ocurrencias_mantenimiento_history_guard BEFORE UPDATE ON ocurrencias_mantenimiento_preventivo
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.plan_id IS NOT OLD.plan_id OR NEW.fecha_programada IS NOT OLD.fecha_programada OR NEW.created_at IS NOT OLD.created_at
              OR OLD.estado IN ('generada', 'omitida')
              OR NOT ((OLD.estado = 'pendiente' AND NEW.estado IN ('generada', 'bloqueada', 'omitida')) OR (OLD.estado = 'bloqueada' AND NEW.estado IN ('bloqueada', 'generada', 'omitida')))
              OR (NEW.estado = 'generada' AND NOT EXISTS (SELECT 1 FROM ordenes_operativas
                WHERE edificio_id = NEW.edificio_id AND ocurrencia_mantenimiento_id = NEW.id AND origen = 'programacion_preventiva'))
            BEGIN SELECT RAISE(ABORT, 'La transición de la ocurrencia no está permitida.'); END;
            CREATE TRIGGER ocurrencias_mantenimiento_delete_guard BEFORE DELETE ON ocurrencias_mantenimiento_preventivo
            BEGIN SELECT RAISE(ABORT, 'Las ocurrencias preventivas no pueden eliminarse.'); END;

            CREATE TRIGGER bitacora_plan_actor_guard BEFORE INSERT ON bitacora_plan_mantenimiento
            WHEN NOT ((NEW.actor_tipo = 'usuario' AND NEW.actor_user_id IS NOT NULL) OR (NEW.actor_tipo = 'sistema' AND NEW.actor_user_id IS NULL))
            BEGIN SELECT RAISE(ABORT, 'El actor de la bitácora del plan es inválido.'); END;
            CREATE TRIGGER bitacora_plan_mantenimiento_guard BEFORE UPDATE ON bitacora_plan_mantenimiento
            BEGIN SELECT RAISE(ABORT, 'La bitácora del plan es inmutable.'); END;
            CREATE TRIGGER bitacora_plan_mantenimiento_delete_guard BEFORE DELETE ON bitacora_plan_mantenimiento
            BEGIN SELECT RAISE(ABORT, 'La bitácora del plan es inmutable.'); END;

            CREATE TRIGGER ordenes_origen_preventivo_insert_guard BEFORE INSERT ON ordenes_operativas
            WHEN NOT ((NEW.origen = 'manual' AND NEW.tipo IN ('incidencia', 'solicitud') AND NEW.ocurrencia_mantenimiento_id IS NULL AND NEW.creada_por_tipo = 'usuario' AND NEW.creada_por_user_id IS NOT NULL)
              OR (NEW.origen = 'programacion_preventiva' AND NEW.tipo = 'mantenimiento_preventivo' AND NEW.ocurrencia_mantenimiento_id IS NOT NULL AND NEW.creada_por_tipo = 'sistema' AND NEW.creada_por_user_id IS NULL))
            BEGIN SELECT RAISE(ABORT, 'El origen y actor de la orden no son coherentes.'); END;
            CREATE TRIGGER ordenes_origen_preventivo_update_guard BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.origen IS NOT OLD.origen OR NEW.ocurrencia_mantenimiento_id IS NOT OLD.ocurrencia_mantenimiento_id OR NEW.creada_por_tipo IS NOT OLD.creada_por_tipo
              OR NOT ((NEW.origen = 'manual' AND NEW.tipo IN ('incidencia', 'solicitud') AND NEW.ocurrencia_mantenimiento_id IS NULL AND NEW.creada_por_tipo = 'usuario' AND NEW.creada_por_user_id IS NOT NULL)
              OR (NEW.origen = 'programacion_preventiva' AND NEW.tipo = 'mantenimiento_preventivo' AND NEW.ocurrencia_mantenimiento_id IS NOT NULL AND NEW.creada_por_tipo = 'sistema' AND NEW.creada_por_user_id IS NULL))
            BEGIN SELECT RAISE(ABORT, 'El origen preventivo de la orden es inmutable.'); END;
            CREATE TRIGGER ordenes_preventive_occurrence_sync AFTER INSERT ON ordenes_operativas
            WHEN NEW.origen = 'programacion_preventiva'
            BEGIN
              UPDATE ocurrencias_mantenimiento_preventivo
              SET estado = 'generada', motivo = NULL, procesada_at = STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW'), updated_at = CURRENT_TIMESTAMP
              WHERE id = NEW.ocurrencia_mantenimiento_id AND edificio_id = NEW.edificio_id AND estado IN ('pendiente', 'bloqueada');
              SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM ocurrencias_mantenimiento_preventivo
                WHERE id = NEW.ocurrencia_mantenimiento_id AND edificio_id = NEW.edificio_id AND estado = 'generada')
                THEN RAISE(ABORT, 'La orden preventiva requiere una ocurrencia abierta.') END;
            END;
            CREATE TRIGGER bitacora_orden_actor_insert_guard BEFORE INSERT ON bitacora_orden_operativa
            WHEN NOT ((NEW.actor_tipo = 'usuario' AND NEW.actor_user_id IS NOT NULL) OR (NEW.actor_tipo = 'sistema' AND NEW.actor_user_id IS NULL))
            BEGIN SELECT RAISE(ABORT, 'El actor de la bitácora operativa es inválido.'); END;
            CREATE TRIGGER bitacora_orden_actor_update_guard BEFORE UPDATE ON bitacora_orden_operativa
            WHEN NEW.actor_tipo IS NOT OLD.actor_tipo OR NEW.actor_user_id IS NOT OLD.actor_user_id
            BEGIN SELECT RAISE(ABORT, 'El actor de la bitácora operativa es inmutable.'); END;
            SQL);
    }
};
