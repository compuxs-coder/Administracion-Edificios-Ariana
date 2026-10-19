<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Las protecciones de Comunicación sólo admiten PostgreSQL y SQLite.');
        }
        if ($driver === 'sqlite') {
            $this->createSqliteGuards();

            return;
        }

        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."comunicados"
                ADD CONSTRAINT comunicados_texto_check CHECK (btrim(titulo) <> '' AND btrim(cuerpo) <> ''),
                ADD CONSTRAINT comunicados_audiencia_check CHECK (audiencia_propietarios OR audiencia_residentes),
                ADD CONSTRAINT comunicados_version_check CHECK (version > 0),
                ADD CONSTRAINT comunicados_totales_check CHECK (
                    total_candidatos >= total_omitidos
                    AND total_entregas <= total_candidatos - total_omitidos
                ),
                ADD CONSTRAINT comunicados_estado_fechas_check CHECK (
                    (estado = 'borrador' AND publicado_por_user_id IS NULL AND publicado_at IS NULL AND archivado_por_user_id IS NULL AND archivado_at IS NULL AND total_candidatos = 0 AND total_entregas = 0 AND total_omitidos = 0)
                    OR (estado = 'publicado' AND publicado_por_user_id IS NOT NULL AND publicado_at IS NOT NULL AND archivado_por_user_id IS NULL AND archivado_at IS NULL)
                    OR (estado = 'archivado' AND publicado_por_user_id IS NOT NULL AND publicado_at IS NOT NULL AND archivado_por_user_id IS NOT NULL AND archivado_at IS NOT NULL AND archivado_at >= publicado_at)
                );
            ALTER TABLE "{$schema}"."documentos_comunicado"
                ADD CONSTRAINT documentos_comunicado_tipo_check CHECK (mime_type IN ('application/pdf', 'image/jpeg', 'image/png')),
                ADD CONSTRAINT documentos_comunicado_tamano_check CHECK (tamano_bytes > 0 AND tamano_bytes <= 10485760),
                ADD CONSTRAINT documentos_comunicado_sha_check CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT documentos_comunicado_nombre_check CHECK (btrim(nombre_original) <> '' AND btrim(ruta_privada) <> '');
            ALTER TABLE "{$schema}"."entregas_comunicado"
                ADD CONSTRAINT entregas_comunicado_canal_check CHECK (canal = 'email'),
                ADD CONSTRAINT entregas_comunicado_destino_check CHECK (destino_normalizado = lower(btrim(destino_normalizado)) AND btrim(destino_normalizado) <> ''),
                ADD CONSTRAINT entregas_comunicado_intentos_check CHECK (intentos BETWEEN 0 AND 100),
                ADD CONSTRAINT entregas_comunicado_forma_check CHECK (
                    (estado = 'pendiente' AND intentos = 0 AND lease_until IS NULL AND ultimo_intento_at IS NULL AND enviado_at IS NULL)
                    OR (estado = 'procesando' AND intentos > 0 AND lease_until IS NOT NULL AND ultimo_intento_at IS NOT NULL AND enviado_at IS NULL)
                    OR (estado IN ('reintento', 'fallida') AND intentos > 0 AND lease_until IS NULL AND ultimo_intento_at IS NOT NULL AND enviado_at IS NULL AND error_codigo IS NOT NULL)
                    OR (estado = 'enviada' AND intentos > 0 AND lease_until IS NULL AND ultimo_intento_at IS NOT NULL AND enviado_at IS NOT NULL AND error_codigo IS NULL AND error_detalle IS NULL)
                );
            ALTER TABLE "{$schema}"."destinatarios_comunicado"
                ADD CONSTRAINT destinatarios_comunicado_audiencia_check CHECK (es_propietario OR es_residente),
                ADD CONSTRAINT destinatarios_comunicado_departamentos_check CHECK (jsonb_typeof(departamentos_snapshot::jsonb) = 'array'),
                ADD CONSTRAINT destinatarios_comunicado_forma_check CHECK (
                    (estado = 'incluido' AND entrega_id IS NOT NULL AND email_snapshot IS NOT NULL AND email_normalizado IS NOT NULL AND motivo_omision IS NULL)
                    OR (estado = 'omitido' AND entrega_id IS NULL AND motivo_omision IN ('sin_correo', 'correo_invalido'))
                );

            CREATE FUNCTION "{$schema}"."guard_comunicado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'borrador' OR NEW.version <> 1 THEN
                        RAISE EXCEPTION 'Un comunicado debe crearse como borrador en su primera versión.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los comunicados no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.creado_por_user_id IS DISTINCT FROM OLD.creado_por_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
                   OR NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'La identidad o versión del comunicado no admite el cambio.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado = 'borrador' AND NEW.estado = 'borrador' THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'borrador' AND NEW.estado = 'publicado' THEN
                    IF NEW.titulo IS DISTINCT FROM OLD.titulo OR NEW.cuerpo IS DISTINCT FROM OLD.cuerpo
                       OR NEW.audiencia_propietarios IS DISTINCT FROM OLD.audiencia_propietarios
                       OR NEW.audiencia_residentes IS DISTINCT FROM OLD.audiencia_residentes
                       OR NEW.publicado_por_user_id IS NULL OR NEW.publicado_at IS NULL THEN
                        RAISE EXCEPTION 'La publicación debe conservar el contenido del borrador y registrar su actor.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.estado = 'publicado' AND NEW.estado = 'archivado' THEN
                    IF NEW.titulo IS DISTINCT FROM OLD.titulo OR NEW.cuerpo IS DISTINCT FROM OLD.cuerpo
                       OR NEW.audiencia_propietarios IS DISTINCT FROM OLD.audiencia_propietarios
                       OR NEW.audiencia_residentes IS DISTINCT FROM OLD.audiencia_residentes
                       OR NEW.publicado_por_user_id IS DISTINCT FROM OLD.publicado_por_user_id
                       OR NEW.publicado_at IS DISTINCT FROM OLD.publicado_at
                       OR NEW.total_candidatos IS DISTINCT FROM OLD.total_candidatos
                       OR NEW.total_entregas IS DISTINCT FROM OLD.total_entregas
                       OR NEW.total_omitidos IS DISTINCT FROM OLD.total_omitidos
                       OR NEW.archivado_por_user_id IS NULL OR NEW.archivado_at IS NULL THEN
                        RAISE EXCEPTION 'El archivado no puede modificar el comunicado publicado.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'La transición del comunicado no está permitida.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER comunicados_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."comunicados"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_comunicado"();

            CREATE FUNCTION "{$schema}"."guard_documento_comunicado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE current_state text;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Los documentos de comunicados no pueden modificarse.' USING ERRCODE = '23514';
                END IF;
                SELECT estado INTO current_state FROM "{$schema}"."comunicados"
                WHERE id = COALESCE(NEW.comunicado_id, OLD.comunicado_id)
                  AND edificio_id = COALESCE(NEW.edificio_id, OLD.edificio_id)
                FOR UPDATE;
                IF current_state IS DISTINCT FROM 'borrador' THEN
                    RAISE EXCEPTION 'Los documentos sólo pueden agregarse o retirarse en un borrador.' USING ERRCODE = '23514';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            \$\$;
            CREATE TRIGGER documentos_comunicado_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."documentos_comunicado"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_documento_comunicado"();

            CREATE FUNCTION "{$schema}"."guard_destinatario_comunicado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE expected_count integer;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'El snapshot de destinatarios es inmutable.' USING ERRCODE = '23514';
                END IF;
                SELECT total_candidatos INTO expected_count FROM "{$schema}"."comunicados"
                WHERE id = NEW.comunicado_id AND edificio_id = NEW.edificio_id AND estado = 'publicado';
                IF expected_count IS NULL THEN
                    RAISE EXCEPTION 'Los destinatarios sólo se materializan durante la publicación.' USING ERRCODE = '23514';
                END IF;
                IF (SELECT count(*) FROM "{$schema}"."destinatarios_comunicado" WHERE comunicado_id = NEW.comunicado_id) >= expected_count THEN
                    RAISE EXCEPTION 'El comunicado ya materializó todos sus destinatarios.' USING ERRCODE = '23514';
                END IF;
                IF NEW.estado = 'incluido' AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."entregas_comunicado"
                    WHERE id = NEW.entrega_id AND edificio_id = NEW.edificio_id AND comunicado_id = NEW.comunicado_id
                      AND destino_normalizado = NEW.email_normalizado
                ) THEN
                    RAISE EXCEPTION 'El correo del destinatario no corresponde con su entrega.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER destinatarios_comunicado_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."destinatarios_comunicado"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_destinatario_comunicado"();

            CREATE FUNCTION "{$schema}"."guard_entrega_comunicado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE expected_count integer;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT total_entregas INTO expected_count FROM "{$schema}"."comunicados"
                    WHERE id = NEW.comunicado_id AND edificio_id = NEW.edificio_id AND estado = 'publicado';
                    IF NEW.estado <> 'pendiente' OR NEW.intentos <> 0 OR expected_count IS NULL THEN
                        RAISE EXCEPTION 'La entrega inicial no es válida.' USING ERRCODE = '23514';
                    END IF;
                    IF (SELECT count(*) FROM "{$schema}"."entregas_comunicado" WHERE comunicado_id = NEW.comunicado_id) >= expected_count THEN
                        RAISE EXCEPTION 'El comunicado ya materializó todas sus entregas.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las entregas de comunicados no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.comunicado_id IS DISTINCT FROM OLD.comunicado_id OR NEW.canal IS DISTINCT FROM OLD.canal
                   OR NEW.destino_normalizado IS DISTINCT FROM OLD.destino_normalizado OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'La identidad de la entrega es inmutable.' USING ERRCODE = '23514';
                END IF;
                IF NOT (
                    (OLD.estado IN ('pendiente', 'reintento') AND NEW.estado = 'procesando' AND NEW.intentos = OLD.intentos + 1)
                    OR (OLD.estado = 'procesando' AND NEW.estado = 'procesando' AND NEW.intentos = OLD.intentos + 1)
                    OR (OLD.estado = 'procesando' AND NEW.estado IN ('enviada', 'reintento', 'fallida') AND NEW.intentos = OLD.intentos)
                    OR (OLD.estado = 'fallida' AND NEW.estado = 'reintento' AND NEW.intentos = OLD.intentos)
                ) THEN
                    RAISE EXCEPTION 'La transición de la entrega no está permitida.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER entregas_comunicado_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."entregas_comunicado"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_entrega_comunicado"();

            CREATE FUNCTION "{$schema}"."verify_comunicado_snapshot"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE target_message_id uuid;
            DECLARE target_building_id uuid;
            DECLARE message_state text;
            DECLARE expected_candidates integer;
            DECLARE expected_deliveries integer;
            DECLARE expected_omitted integer;
            BEGIN
                IF TG_TABLE_NAME = 'comunicados' THEN
                    target_message_id := NEW.id;
                    target_building_id := NEW.edificio_id;
                ELSIF TG_OP = 'DELETE' THEN
                    target_message_id := OLD.comunicado_id;
                    target_building_id := OLD.edificio_id;
                ELSE
                    target_message_id := NEW.comunicado_id;
                    target_building_id := NEW.edificio_id;
                END IF;
                SELECT estado, total_candidatos, total_entregas, total_omitidos
                INTO message_state, expected_candidates, expected_deliveries, expected_omitted
                FROM "{$schema}"."comunicados"
                WHERE id = target_message_id AND edificio_id = target_building_id;
                IF message_state IS NULL OR message_state = 'borrador' THEN
                    RETURN NULL;
                END IF;
                IF (SELECT count(*) FROM "{$schema}"."destinatarios_comunicado" WHERE comunicado_id = target_message_id) <> expected_candidates
                   OR (SELECT count(*) FROM "{$schema}"."entregas_comunicado" WHERE comunicado_id = target_message_id) <> expected_deliveries
                   OR (SELECT count(*) FROM "{$schema}"."destinatarios_comunicado" WHERE comunicado_id = target_message_id AND estado = 'omitido') <> expected_omitted
                   OR EXISTS (
                       SELECT 1 FROM "{$schema}"."destinatarios_comunicado" recipient
                       LEFT JOIN "{$schema}"."entregas_comunicado" delivery
                         ON delivery.id = recipient.entrega_id AND delivery.edificio_id = recipient.edificio_id AND delivery.comunicado_id = recipient.comunicado_id
                       WHERE recipient.comunicado_id = target_message_id
                         AND recipient.estado = 'incluido'
                         AND (delivery.id IS NULL OR recipient.email_normalizado IS DISTINCT FROM delivery.destino_normalizado)
                   )
                   OR EXISTS (
                       SELECT 1 FROM "{$schema}"."entregas_comunicado" delivery
                       WHERE delivery.comunicado_id = target_message_id
                         AND NOT EXISTS (SELECT 1 FROM "{$schema}"."destinatarios_comunicado" recipient WHERE recipient.entrega_id = delivery.id)
                   ) THEN
                    RAISE EXCEPTION 'El snapshot publicado no coincide con sus totales o entregas.' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            \$\$;
            CREATE CONSTRAINT TRIGGER comunicados_snapshot_consistency AFTER INSERT OR UPDATE ON "{$schema}"."comunicados"
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "{$schema}"."verify_comunicado_snapshot"();

            CREATE FUNCTION "{$schema}"."prevent_bitacora_comunicacion_mutation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'La bitácora de Comunicación es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER bitacora_comunicacion_guard BEFORE UPDATE OR DELETE ON "{$schema}"."bitacora_comunicacion"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_bitacora_comunicacion_mutation"();
            SQL);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Las protecciones de Comunicación sólo admiten PostgreSQL y SQLite.');
        }
        if (DB::table($this->table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_comunicacion')->exists()
            || DB::table($this->table('invitaciones_edificio'))->where('rol_codigo', 'gestor_comunicacion')->exists()) {
            throw new RuntimeException('No se puede iniciar el rollback de Comunicación mientras gestor_comunicacion tenga asignaciones o invitaciones.');
        }
        foreach (['bitacora_comunicacion', 'destinatarios_comunicado', 'entregas_comunicado', 'documentos_comunicado', 'comunicados'] as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se pueden retirar las protecciones de Comunicación mientras exista historial.');
            }
        }
        if ($driver === 'sqlite') {
            foreach ($this->sqliteTriggers() as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS destinatarios_comunicado_snapshot_consistency ON "{$schema}"."destinatarios_comunicado";
            DROP TRIGGER IF EXISTS entregas_comunicado_snapshot_consistency ON "{$schema}"."entregas_comunicado";
            DROP TRIGGER IF EXISTS comunicados_snapshot_consistency ON "{$schema}"."comunicados";
            DROP FUNCTION IF EXISTS "{$schema}"."verify_comunicado_snapshot"();
            DROP TRIGGER IF EXISTS bitacora_comunicacion_guard ON "{$schema}"."bitacora_comunicacion";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_bitacora_comunicacion_mutation"();
            DROP TRIGGER IF EXISTS entregas_comunicado_guard ON "{$schema}"."entregas_comunicado";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_entrega_comunicado"();
            DROP TRIGGER IF EXISTS destinatarios_comunicado_guard ON "{$schema}"."destinatarios_comunicado";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_destinatario_comunicado"();
            DROP TRIGGER IF EXISTS documentos_comunicado_guard ON "{$schema}"."documentos_comunicado";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_documento_comunicado"();
            DROP TRIGGER IF EXISTS comunicados_guard ON "{$schema}"."comunicados";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_comunicado"();
            ALTER TABLE "{$schema}"."destinatarios_comunicado" DROP CONSTRAINT IF EXISTS destinatarios_comunicado_forma_check, DROP CONSTRAINT IF EXISTS destinatarios_comunicado_departamentos_check, DROP CONSTRAINT IF EXISTS destinatarios_comunicado_audiencia_check;
            ALTER TABLE "{$schema}"."entregas_comunicado" DROP CONSTRAINT IF EXISTS entregas_comunicado_forma_check, DROP CONSTRAINT IF EXISTS entregas_comunicado_intentos_check, DROP CONSTRAINT IF EXISTS entregas_comunicado_destino_check, DROP CONSTRAINT IF EXISTS entregas_comunicado_canal_check;
            ALTER TABLE "{$schema}"."documentos_comunicado" DROP CONSTRAINT IF EXISTS documentos_comunicado_nombre_check, DROP CONSTRAINT IF EXISTS documentos_comunicado_sha_check, DROP CONSTRAINT IF EXISTS documentos_comunicado_tamano_check, DROP CONSTRAINT IF EXISTS documentos_comunicado_tipo_check;
            ALTER TABLE "{$schema}"."comunicados" DROP CONSTRAINT IF EXISTS comunicados_estado_fechas_check, DROP CONSTRAINT IF EXISTS comunicados_totales_check, DROP CONSTRAINT IF EXISTS comunicados_version_check, DROP CONSTRAINT IF EXISTS comunicados_audiencia_check, DROP CONSTRAINT IF EXISTS comunicados_texto_check;
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER comunicados_shape_insert_guard BEFORE INSERT ON comunicados
            WHEN trim(NEW.titulo) = '' OR trim(NEW.cuerpo) = '' OR (NEW.audiencia_propietarios = 0 AND NEW.audiencia_residentes = 0)
              OR NEW.estado <> 'borrador' OR NEW.version <> 1 OR NEW.publicado_por_user_id IS NOT NULL OR NEW.publicado_at IS NOT NULL
              OR NEW.archivado_por_user_id IS NOT NULL OR NEW.archivado_at IS NOT NULL OR NEW.total_candidatos <> 0 OR NEW.total_entregas <> 0 OR NEW.total_omitidos <> 0
            BEGIN SELECT RAISE(ABORT, 'El borrador del comunicado tiene una forma inválida.'); END;
            CREATE TRIGGER comunicados_shape_update_guard BEFORE UPDATE ON comunicados
            WHEN trim(NEW.titulo) = '' OR trim(NEW.cuerpo) = '' OR (NEW.audiencia_propietarios = 0 AND NEW.audiencia_residentes = 0)
              OR NEW.total_candidatos < NEW.total_omitidos OR NEW.total_entregas > NEW.total_candidatos - NEW.total_omitidos
              OR (NEW.estado = 'borrador' AND (NEW.publicado_por_user_id IS NOT NULL OR NEW.publicado_at IS NOT NULL OR NEW.archivado_por_user_id IS NOT NULL OR NEW.archivado_at IS NOT NULL OR NEW.total_candidatos <> 0 OR NEW.total_entregas <> 0 OR NEW.total_omitidos <> 0))
              OR (NEW.estado = 'publicado' AND (NEW.publicado_por_user_id IS NULL OR NEW.publicado_at IS NULL OR NEW.archivado_por_user_id IS NOT NULL OR NEW.archivado_at IS NOT NULL))
              OR (NEW.estado = 'archivado' AND (NEW.publicado_por_user_id IS NULL OR NEW.publicado_at IS NULL OR NEW.archivado_por_user_id IS NULL OR NEW.archivado_at IS NULL OR NEW.archivado_at < NEW.publicado_at))
              OR (NEW.estado IN ('publicado', 'archivado') AND ((SELECT COUNT(*) FROM destinatarios_comunicado WHERE comunicado_id = NEW.id) <> NEW.total_candidatos OR (SELECT COUNT(*) FROM entregas_comunicado WHERE comunicado_id = NEW.id) <> NEW.total_entregas OR (SELECT COUNT(*) FROM destinatarios_comunicado WHERE comunicado_id = NEW.id AND estado = 'omitido') <> NEW.total_omitidos))
            BEGIN SELECT RAISE(ABORT, 'El comunicado tiene una forma inválida.'); END;
            CREATE TRIGGER comunicados_history_guard BEFORE UPDATE ON comunicados
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.creado_por_user_id IS NOT OLD.creado_por_user_id
              OR NEW.created_at IS NOT OLD.created_at OR NEW.version <> OLD.version + 1
              OR NOT (
                (OLD.estado = 'borrador' AND NEW.estado = 'borrador')
                OR (OLD.estado = 'borrador' AND NEW.estado = 'publicado' AND NEW.titulo IS OLD.titulo AND NEW.cuerpo IS OLD.cuerpo AND NEW.audiencia_propietarios IS OLD.audiencia_propietarios AND NEW.audiencia_residentes IS OLD.audiencia_residentes)
                OR (OLD.estado = 'publicado' AND NEW.estado = 'archivado' AND NEW.titulo IS OLD.titulo AND NEW.cuerpo IS OLD.cuerpo AND NEW.audiencia_propietarios IS OLD.audiencia_propietarios AND NEW.audiencia_residentes IS OLD.audiencia_residentes AND NEW.publicado_por_user_id IS OLD.publicado_por_user_id AND NEW.publicado_at IS OLD.publicado_at AND NEW.total_candidatos IS OLD.total_candidatos AND NEW.total_entregas IS OLD.total_entregas AND NEW.total_omitidos IS OLD.total_omitidos)
              )
            BEGIN SELECT RAISE(ABORT, 'La transición o historia del comunicado no es válida.'); END;
            CREATE TRIGGER comunicados_delete_guard BEFORE DELETE ON comunicados
            BEGIN SELECT RAISE(ABORT, 'Los comunicados no pueden eliminarse.'); END;

            CREATE TRIGGER documentos_comunicado_shape_guard BEFORE INSERT ON documentos_comunicado
            WHEN NEW.mime_type NOT IN ('application/pdf', 'image/jpeg', 'image/png') OR NEW.tamano_bytes < 1 OR NEW.tamano_bytes > 10485760
              OR length(NEW.sha256) <> 64 OR NEW.sha256 <> lower(NEW.sha256) OR NEW.sha256 GLOB '*[^0-9a-f]*' OR trim(NEW.nombre_original) = '' OR trim(NEW.ruta_privada) = ''
              OR NOT EXISTS (SELECT 1 FROM comunicados WHERE id = NEW.comunicado_id AND edificio_id = NEW.edificio_id AND estado = 'borrador')
            BEGIN SELECT RAISE(ABORT, 'El documento del comunicado no es válido.'); END;
            CREATE TRIGGER documentos_comunicado_update_guard BEFORE UPDATE ON documentos_comunicado
            BEGIN SELECT RAISE(ABORT, 'Los documentos de comunicados no pueden modificarse.'); END;
            CREATE TRIGGER documentos_comunicado_delete_guard BEFORE DELETE ON documentos_comunicado
            WHEN NOT EXISTS (SELECT 1 FROM comunicados WHERE id = OLD.comunicado_id AND edificio_id = OLD.edificio_id AND estado = 'borrador')
            BEGIN SELECT RAISE(ABORT, 'Los documentos publicados no pueden retirarse.'); END;

            CREATE TRIGGER destinatarios_comunicado_shape_guard BEFORE INSERT ON destinatarios_comunicado
            WHEN (NEW.es_propietario = 0 AND NEW.es_residente = 0) OR json_valid(NEW.departamentos_snapshot) = 0 OR json_type(NEW.departamentos_snapshot) <> 'array'
              OR NOT EXISTS (SELECT 1 FROM comunicados WHERE id = NEW.comunicado_id AND edificio_id = NEW.edificio_id AND estado IN ('borrador', 'publicado'))
              OR (EXISTS (SELECT 1 FROM comunicados WHERE id = NEW.comunicado_id AND estado = 'publicado') AND (SELECT COUNT(*) FROM destinatarios_comunicado WHERE comunicado_id = NEW.comunicado_id) >= (SELECT total_candidatos FROM comunicados WHERE id = NEW.comunicado_id))
              OR (NEW.estado = 'incluido' AND (NEW.entrega_id IS NULL OR NEW.email_snapshot IS NULL OR NEW.email_normalizado IS NULL OR NEW.motivo_omision IS NOT NULL))
              OR (NEW.estado = 'incluido' AND NOT EXISTS (SELECT 1 FROM entregas_comunicado WHERE id = NEW.entrega_id AND edificio_id = NEW.edificio_id AND comunicado_id = NEW.comunicado_id AND destino_normalizado = NEW.email_normalizado))
              OR (NEW.estado = 'omitido' AND (NEW.entrega_id IS NOT NULL OR NEW.motivo_omision NOT IN ('sin_correo', 'correo_invalido')))
            BEGIN SELECT RAISE(ABORT, 'El snapshot de destinatario no es válido.'); END;
            CREATE TRIGGER destinatarios_comunicado_update_guard BEFORE UPDATE ON destinatarios_comunicado
            BEGIN SELECT RAISE(ABORT, 'El snapshot de destinatarios es inmutable.'); END;
            CREATE TRIGGER destinatarios_comunicado_delete_guard BEFORE DELETE ON destinatarios_comunicado
            BEGIN SELECT RAISE(ABORT, 'El snapshot de destinatarios es inmutable.'); END;

            CREATE TRIGGER entregas_comunicado_shape_insert_guard BEFORE INSERT ON entregas_comunicado
            WHEN NEW.canal <> 'email' OR NEW.destino_normalizado <> lower(trim(NEW.destino_normalizado)) OR trim(NEW.destino_normalizado) = ''
              OR NEW.estado <> 'pendiente' OR NEW.intentos <> 0 OR NEW.lease_until IS NOT NULL OR NEW.ultimo_intento_at IS NOT NULL OR NEW.enviado_at IS NOT NULL
              OR NOT EXISTS (SELECT 1 FROM comunicados WHERE id = NEW.comunicado_id AND edificio_id = NEW.edificio_id AND estado IN ('borrador', 'publicado'))
              OR (EXISTS (SELECT 1 FROM comunicados WHERE id = NEW.comunicado_id AND estado = 'publicado') AND (SELECT COUNT(*) FROM entregas_comunicado WHERE comunicado_id = NEW.comunicado_id) >= (SELECT total_entregas FROM comunicados WHERE id = NEW.comunicado_id))
            BEGIN SELECT RAISE(ABORT, 'La entrega inicial no es válida.'); END;
            CREATE TRIGGER entregas_comunicado_shape_update_guard BEFORE UPDATE ON entregas_comunicado
            WHEN NEW.canal <> 'email' OR NEW.destino_normalizado <> lower(trim(NEW.destino_normalizado)) OR trim(NEW.destino_normalizado) = '' OR NEW.intentos < 0 OR NEW.intentos > 100
              OR (NEW.estado = 'procesando' AND (NEW.intentos < 1 OR NEW.lease_until IS NULL OR NEW.ultimo_intento_at IS NULL OR NEW.enviado_at IS NOT NULL))
              OR (NEW.estado IN ('reintento', 'fallida') AND (NEW.intentos < 1 OR NEW.lease_until IS NOT NULL OR NEW.ultimo_intento_at IS NULL OR NEW.enviado_at IS NOT NULL OR NEW.error_codigo IS NULL))
              OR (NEW.estado = 'enviada' AND (NEW.intentos < 1 OR NEW.lease_until IS NOT NULL OR NEW.ultimo_intento_at IS NULL OR NEW.enviado_at IS NULL OR NEW.error_codigo IS NOT NULL OR NEW.error_detalle IS NOT NULL))
            BEGIN SELECT RAISE(ABORT, 'El estado de la entrega no es coherente.'); END;
            CREATE TRIGGER entregas_comunicado_history_guard BEFORE UPDATE ON entregas_comunicado
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.comunicado_id IS NOT OLD.comunicado_id
              OR NEW.canal IS NOT OLD.canal OR NEW.destino_normalizado IS NOT OLD.destino_normalizado OR NEW.created_at IS NOT OLD.created_at
              OR NOT (
                (OLD.estado IN ('pendiente', 'reintento') AND NEW.estado = 'procesando' AND NEW.intentos = OLD.intentos + 1)
                OR (OLD.estado = 'procesando' AND NEW.estado = 'procesando' AND NEW.intentos = OLD.intentos + 1)
                OR (OLD.estado = 'procesando' AND NEW.estado IN ('enviada', 'reintento', 'fallida') AND NEW.intentos = OLD.intentos)
                OR (OLD.estado = 'fallida' AND NEW.estado = 'reintento' AND NEW.intentos = OLD.intentos)
              )
            BEGIN SELECT RAISE(ABORT, 'La transición o identidad de la entrega no es válida.'); END;
            CREATE TRIGGER entregas_comunicado_delete_guard BEFORE DELETE ON entregas_comunicado
            BEGIN SELECT RAISE(ABORT, 'Las entregas de comunicados no pueden eliminarse.'); END;

            CREATE TRIGGER bitacora_comunicacion_update_guard BEFORE UPDATE ON bitacora_comunicacion
            BEGIN SELECT RAISE(ABORT, 'La bitácora de Comunicación es inmutable.'); END;
            CREATE TRIGGER bitacora_comunicacion_delete_guard BEFORE DELETE ON bitacora_comunicacion
            BEGIN SELECT RAISE(ABORT, 'La bitácora de Comunicación es inmutable.'); END;
            SQL);
    }

    /** @return list<string> */
    private function sqliteTriggers(): array
    {
        return [
            'comunicados_shape_insert_guard', 'comunicados_shape_update_guard', 'comunicados_history_guard', 'comunicados_delete_guard',
            'documentos_comunicado_shape_guard', 'documentos_comunicado_update_guard', 'documentos_comunicado_delete_guard',
            'destinatarios_comunicado_shape_guard', 'destinatarios_comunicado_update_guard', 'destinatarios_comunicado_delete_guard',
            'entregas_comunicado_shape_insert_guard', 'entregas_comunicado_shape_update_guard', 'entregas_comunicado_history_guard', 'entregas_comunicado_delete_guard',
            'bitacora_comunicacion_update_guard', 'bitacora_comunicacion_delete_guard',
        ];
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
