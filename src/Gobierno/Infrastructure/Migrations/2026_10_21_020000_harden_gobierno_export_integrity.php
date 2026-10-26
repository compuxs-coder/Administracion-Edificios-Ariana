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
            throw new RuntimeException('Las protecciones de exportaciones sólo admiten PostgreSQL y SQLite.');
        }
        $driver === 'pgsql' ? $this->createPostgresGuards() : $this->createSqliteGuards();
    }

    public function down(): void
    {
        if (DB::table($this->table('rol_permisos'))->where('permiso_codigo', 'gobierno.exportar')
            ->whereNotIn('rol_codigo', ['administrador', 'gestor_gobierno'])->exists()) {
            throw new RuntimeException('No se puede retirar la infraestructura mientras gobierno.exportar esté concedido a otros roles.');
        }
        if (DB::table($this->table('exportaciones_auditoria'))->exists()
            || DB::table($this->table('bitacora_exportacion_auditoria'))->exists()) {
            throw new RuntimeException('No se pueden retirar las protecciones mientras exista historial de exportaciones.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            $schema = (string) config('database.application_schema');
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS bitacora_exportacion_auditoria_guard ON "{$schema}"."bitacora_exportacion_auditoria";
                DROP FUNCTION IF EXISTS "{$schema}"."guard_bitacora_exportacion_auditoria"();
                DROP TRIGGER IF EXISTS exportacion_auditoria_guard ON "{$schema}"."exportaciones_auditoria";
                DROP FUNCTION IF EXISTS "{$schema}"."guard_exportacion_auditoria"();
                DROP TRIGGER IF EXISTS exportacion_auditoria_edificios_guard ON "{$schema}"."exportacion_auditoria_edificios";
                DROP FUNCTION IF EXISTS "{$schema}"."guard_alcance_exportacion_auditoria"();
                ALTER TABLE "{$schema}"."exportaciones_auditoria"
                    DROP CONSTRAINT IF EXISTS exportaciones_auditoria_rango_check,
                    DROP CONSTRAINT IF EXISTS exportaciones_auditoria_progreso_check,
                    DROP CONSTRAINT IF EXISTS exportaciones_auditoria_sha_check,
                    DROP CONSTRAINT IF EXISTS exportaciones_auditoria_estado_forma_check;
                ALTER TABLE "{$schema}"."exportacion_auditoria_edificios"
                    DROP CONSTRAINT IF EXISTS exportacion_auditoria_edificios_forma_check;
                ALTER TABLE "{$schema}"."bitacora_exportacion_auditoria"
                    DROP CONSTRAINT IF EXISTS bitacora_exportacion_auditoria_actor_check;
                SQL);
        } else {
            foreach ([
                'bitacora_exportacion_auditoria_update_guard', 'bitacora_exportacion_auditoria_delete_guard',
                'bitacora_exportacion_auditoria_insert_guard', 'exportaciones_auditoria_insert_guard',
                'exportaciones_auditoria_update_guard', 'exportacion_auditoria_edificios_insert_guard',
                'exportacion_auditoria_edificios_update_guard', 'exportacion_auditoria_edificios_delete_guard',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    private function createPostgresGuards(): void
    {
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."exportaciones_auditoria"
                ADD CONSTRAINT exportaciones_auditoria_rango_check CHECK (desde_utc < hasta_utc AND corte_at > desde_utc AND corte_at <= hasta_utc),
                ADD CONSTRAINT exportaciones_auditoria_progreso_check CHECK (eventos_procesados >= 0 AND (total_eventos IS NULL OR total_eventos >= eventos_procesados)),
                ADD CONSTRAINT exportaciones_auditoria_sha_check CHECK (sha256 IS NULL OR sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT exportaciones_auditoria_estado_forma_check CHECK (
                    (estado = 'pendiente' AND lease_token IS NULL AND lease_until IS NULL AND total_eventos IS NULL
                        AND disco IS NULL AND ruta_privada IS NULL AND nombre_archivo IS NULL AND mime_type IS NULL
                        AND tamano_bytes IS NULL AND sha256 IS NULL AND completada_at IS NULL AND expires_at IS NULL AND archivo_eliminado_at IS NULL)
                    OR (estado = 'procesando' AND lease_token IS NOT NULL AND lease_until IS NOT NULL AND iniciada_at IS NOT NULL AND total_eventos IS NULL
                        AND disco IS NULL AND ruta_privada IS NULL AND nombre_archivo IS NULL AND mime_type IS NULL
                        AND tamano_bytes IS NULL AND sha256 IS NULL AND completada_at IS NULL AND expires_at IS NULL AND archivo_eliminado_at IS NULL)
                    OR (estado = 'completada' AND lease_token IS NULL AND lease_until IS NULL AND total_eventos IS NOT NULL
                        AND disco IS NOT NULL AND ruta_privada IS NOT NULL AND nombre_archivo IS NOT NULL AND mime_type IS NOT NULL
                        AND tamano_bytes IS NOT NULL AND sha256 IS NOT NULL AND completada_at IS NOT NULL AND expires_at IS NOT NULL
                        AND error_codigo IS NULL AND archivo_eliminado_at IS NULL)
                    OR (estado = 'fallida' AND lease_token IS NULL AND lease_until IS NULL AND error_codigo IS NOT NULL AND fallida_at IS NOT NULL
                        AND total_eventos IS NULL AND disco IS NULL AND ruta_privada IS NULL AND nombre_archivo IS NULL AND mime_type IS NULL
                        AND tamano_bytes IS NULL AND sha256 IS NULL AND completada_at IS NULL AND expires_at IS NULL AND archivo_eliminado_at IS NULL)
                    OR (estado = 'expirada' AND lease_token IS NULL AND lease_until IS NULL AND total_eventos IS NOT NULL
                        AND disco IS NOT NULL AND ruta_privada IS NOT NULL AND nombre_archivo IS NOT NULL AND mime_type IS NOT NULL
                        AND tamano_bytes IS NOT NULL AND sha256 IS NOT NULL AND completada_at IS NOT NULL AND expires_at IS NOT NULL)
                );
            ALTER TABLE "{$schema}"."exportacion_auditoria_edificios"
                ADD CONSTRAINT exportacion_auditoria_edificios_forma_check CHECK (
                    btrim(nombre_snapshot) <> '' AND btrim(timezone_snapshot) <> ''
                    AND jsonb_typeof(permisos_fuente::jsonb) = 'array'
                );
            ALTER TABLE "{$schema}"."bitacora_exportacion_auditoria"
                ADD CONSTRAINT bitacora_exportacion_auditoria_actor_check CHECK (
                    (actor_tipo = 'usuario' AND actor_user_id IS NOT NULL)
                    OR (actor_tipo = 'sistema' AND actor_user_id IS NULL)
                );
            CREATE FUNCTION "{$schema}"."guard_bitacora_exportacion_auditoria"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'La bitácora de exportaciones es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER bitacora_exportacion_auditoria_guard
            BEFORE UPDATE OR DELETE ON "{$schema}"."bitacora_exportacion_auditoria"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_bitacora_exportacion_auditoria"();

            CREATE FUNCTION "{$schema}"."guard_exportacion_auditoria"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.id IS DISTINCT FROM NEW.id
                   OR OLD.solicitante_user_id IS DISTINCT FROM NEW.solicitante_user_id
                   OR OLD.idempotency_key IS DISTINCT FROM NEW.idempotency_key
                   OR OLD.formato IS DISTINCT FROM NEW.formato
                   OR OLD.fuente IS DISTINCT FROM NEW.fuente
                   OR OLD.tipo IS DISTINCT FROM NEW.tipo
                   OR OLD.desde_utc IS DISTINCT FROM NEW.desde_utc
                   OR OLD.hasta_utc IS DISTINCT FROM NEW.hasta_utc
                   OR OLD.corte_at IS DISTINCT FROM NEW.corte_at THEN
                    RAISE EXCEPTION 'La identidad y el alcance de la exportación son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado IS DISTINCT FROM NEW.estado AND NOT (
                    (OLD.estado = 'pendiente' AND NEW.estado = 'procesando')
                    OR (OLD.estado = 'procesando' AND NEW.estado IN ('pendiente', 'completada', 'fallida'))
                    OR (OLD.estado = 'completada' AND NEW.estado = 'expirada')
                ) THEN
                    RAISE EXCEPTION 'Transición de exportación no permitida.' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado IN ('completada', 'expirada') AND (
                    OLD.intentos IS DISTINCT FROM NEW.intentos
                    OR OLD.total_eventos IS DISTINCT FROM NEW.total_eventos
                    OR OLD.eventos_procesados IS DISTINCT FROM NEW.eventos_procesados
                    OR OLD.disco IS DISTINCT FROM NEW.disco
                    OR OLD.ruta_privada IS DISTINCT FROM NEW.ruta_privada
                    OR OLD.nombre_archivo IS DISTINCT FROM NEW.nombre_archivo
                    OR OLD.mime_type IS DISTINCT FROM NEW.mime_type
                    OR OLD.tamano_bytes IS DISTINCT FROM NEW.tamano_bytes
                    OR OLD.sha256 IS DISTINCT FROM NEW.sha256
                    OR OLD.completada_at IS DISTINCT FROM NEW.completada_at
                    OR OLD.expires_at IS DISTINCT FROM NEW.expires_at
                ) THEN
                    RAISE EXCEPTION 'Los metadatos del archivo completado son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF (OLD.archivo_eliminado_at IS NOT NULL AND OLD.archivo_eliminado_at IS DISTINCT FROM NEW.archivo_eliminado_at)
                   OR (NEW.archivo_eliminado_at IS NOT NULL AND NEW.estado <> 'expirada') THEN
                    RAISE EXCEPTION 'La marca de eliminación del archivo no es válida.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER exportacion_auditoria_guard
            BEFORE UPDATE ON "{$schema}"."exportaciones_auditoria"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_exportacion_auditoria"();

            CREATE FUNCTION "{$schema}"."guard_alcance_exportacion_auditoria"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE export_state text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT estado::text INTO export_state
                    FROM "{$schema}"."exportaciones_auditoria"
                    WHERE id = NEW.exportacion_id;
                    IF export_state <> 'pendiente' OR EXISTS (
                        SELECT 1 FROM "{$schema}"."bitacora_exportacion_auditoria"
                        WHERE exportacion_id = NEW.exportacion_id
                    ) THEN
                        RAISE EXCEPTION 'El alcance de la exportación ya fue sellado.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'El alcance de la exportación es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER exportacion_auditoria_edificios_guard
            BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."exportacion_auditoria_edificios"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_alcance_exportacion_auditoria"();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER exportaciones_auditoria_insert_guard BEFORE INSERT ON exportaciones_auditoria
            WHEN NEW.desde_utc >= NEW.hasta_utc OR NEW.corte_at <= NEW.desde_utc OR NEW.corte_at > NEW.hasta_utc
              OR NEW.eventos_procesados < 0 OR (NEW.total_eventos IS NOT NULL AND NEW.total_eventos < NEW.eventos_procesados)
              OR (NEW.sha256 IS NOT NULL AND (length(NEW.sha256) <> 64 OR NEW.sha256 GLOB '*[^0-9a-f]*'))
              OR NOT (
                (NEW.estado = 'pendiente' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.total_eventos IS NULL
                    AND NEW.disco IS NULL AND NEW.ruta_privada IS NULL AND NEW.nombre_archivo IS NULL AND NEW.mime_type IS NULL
                    AND NEW.tamano_bytes IS NULL AND NEW.sha256 IS NULL AND NEW.completada_at IS NULL AND NEW.expires_at IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'procesando' AND NEW.lease_token IS NOT NULL AND NEW.lease_until IS NOT NULL AND NEW.iniciada_at IS NOT NULL AND NEW.total_eventos IS NULL
                    AND NEW.disco IS NULL AND NEW.ruta_privada IS NULL AND NEW.nombre_archivo IS NULL AND NEW.mime_type IS NULL
                    AND NEW.tamano_bytes IS NULL AND NEW.sha256 IS NULL AND NEW.completada_at IS NULL AND NEW.expires_at IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'completada' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.total_eventos IS NOT NULL
                    AND NEW.disco IS NOT NULL AND NEW.ruta_privada IS NOT NULL AND NEW.nombre_archivo IS NOT NULL AND NEW.mime_type IS NOT NULL
                    AND NEW.tamano_bytes IS NOT NULL AND NEW.sha256 IS NOT NULL AND NEW.completada_at IS NOT NULL AND NEW.expires_at IS NOT NULL
                    AND NEW.error_codigo IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'fallida' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.error_codigo IS NOT NULL AND NEW.fallida_at IS NOT NULL
                    AND NEW.total_eventos IS NULL AND NEW.disco IS NULL AND NEW.ruta_privada IS NULL AND NEW.nombre_archivo IS NULL AND NEW.mime_type IS NULL
                    AND NEW.tamano_bytes IS NULL AND NEW.sha256 IS NULL AND NEW.completada_at IS NULL AND NEW.expires_at IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'expirada' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.total_eventos IS NOT NULL
                    AND NEW.disco IS NOT NULL AND NEW.ruta_privada IS NOT NULL AND NEW.nombre_archivo IS NOT NULL AND NEW.mime_type IS NOT NULL
                    AND NEW.tamano_bytes IS NOT NULL AND NEW.sha256 IS NOT NULL AND NEW.completada_at IS NOT NULL AND NEW.expires_at IS NOT NULL)
              )
            BEGIN SELECT RAISE(ABORT, 'La exportación de auditoría no tiene una forma válida.'); END;

            CREATE TRIGGER exportaciones_auditoria_update_guard BEFORE UPDATE ON exportaciones_auditoria
            WHEN OLD.id IS NOT NEW.id OR OLD.solicitante_user_id IS NOT NEW.solicitante_user_id
              OR OLD.idempotency_key IS NOT NEW.idempotency_key OR OLD.formato IS NOT NEW.formato
              OR OLD.fuente IS NOT NEW.fuente OR OLD.tipo IS NOT NEW.tipo OR OLD.desde_utc IS NOT NEW.desde_utc
              OR OLD.hasta_utc IS NOT NEW.hasta_utc OR OLD.corte_at IS NOT NEW.corte_at
              OR NEW.desde_utc >= NEW.hasta_utc OR NEW.corte_at <= NEW.desde_utc OR NEW.corte_at > NEW.hasta_utc
              OR NEW.eventos_procesados < 0 OR (NEW.total_eventos IS NOT NULL AND NEW.total_eventos < NEW.eventos_procesados)
              OR (NEW.sha256 IS NOT NULL AND (length(NEW.sha256) <> 64 OR NEW.sha256 GLOB '*[^0-9a-f]*'))
              OR (OLD.estado IS NOT NEW.estado AND NOT (
                (OLD.estado = 'pendiente' AND NEW.estado = 'procesando')
                OR (OLD.estado = 'procesando' AND NEW.estado IN ('pendiente', 'completada', 'fallida'))
                OR (OLD.estado = 'completada' AND NEW.estado = 'expirada')
              ))
              OR (OLD.estado IN ('completada', 'expirada') AND (
                OLD.intentos IS NOT NEW.intentos OR OLD.total_eventos IS NOT NEW.total_eventos
                OR OLD.eventos_procesados IS NOT NEW.eventos_procesados OR OLD.disco IS NOT NEW.disco
                OR OLD.ruta_privada IS NOT NEW.ruta_privada OR OLD.nombre_archivo IS NOT NEW.nombre_archivo
                OR OLD.mime_type IS NOT NEW.mime_type OR OLD.tamano_bytes IS NOT NEW.tamano_bytes
                OR OLD.sha256 IS NOT NEW.sha256 OR OLD.completada_at IS NOT NEW.completada_at
                OR OLD.expires_at IS NOT NEW.expires_at
              ))
              OR (OLD.archivo_eliminado_at IS NOT NULL AND OLD.archivo_eliminado_at IS NOT NEW.archivo_eliminado_at)
              OR (NEW.archivo_eliminado_at IS NOT NULL AND NEW.estado <> 'expirada')
              OR NOT (
                (NEW.estado = 'pendiente' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.total_eventos IS NULL
                    AND NEW.disco IS NULL AND NEW.ruta_privada IS NULL AND NEW.nombre_archivo IS NULL AND NEW.mime_type IS NULL
                    AND NEW.tamano_bytes IS NULL AND NEW.sha256 IS NULL AND NEW.completada_at IS NULL AND NEW.expires_at IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'procesando' AND NEW.lease_token IS NOT NULL AND NEW.lease_until IS NOT NULL AND NEW.iniciada_at IS NOT NULL AND NEW.total_eventos IS NULL
                    AND NEW.disco IS NULL AND NEW.ruta_privada IS NULL AND NEW.nombre_archivo IS NULL AND NEW.mime_type IS NULL
                    AND NEW.tamano_bytes IS NULL AND NEW.sha256 IS NULL AND NEW.completada_at IS NULL AND NEW.expires_at IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'completada' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.total_eventos IS NOT NULL
                    AND NEW.disco IS NOT NULL AND NEW.ruta_privada IS NOT NULL AND NEW.nombre_archivo IS NOT NULL AND NEW.mime_type IS NOT NULL
                    AND NEW.tamano_bytes IS NOT NULL AND NEW.sha256 IS NOT NULL AND NEW.completada_at IS NOT NULL AND NEW.expires_at IS NOT NULL
                    AND NEW.error_codigo IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'fallida' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.error_codigo IS NOT NULL AND NEW.fallida_at IS NOT NULL
                    AND NEW.total_eventos IS NULL AND NEW.disco IS NULL AND NEW.ruta_privada IS NULL AND NEW.nombre_archivo IS NULL AND NEW.mime_type IS NULL
                    AND NEW.tamano_bytes IS NULL AND NEW.sha256 IS NULL AND NEW.completada_at IS NULL AND NEW.expires_at IS NULL AND NEW.archivo_eliminado_at IS NULL)
                OR (NEW.estado = 'expirada' AND NEW.lease_token IS NULL AND NEW.lease_until IS NULL AND NEW.total_eventos IS NOT NULL
                    AND NEW.disco IS NOT NULL AND NEW.ruta_privada IS NOT NULL AND NEW.nombre_archivo IS NOT NULL AND NEW.mime_type IS NOT NULL
                    AND NEW.tamano_bytes IS NOT NULL AND NEW.sha256 IS NOT NULL AND NEW.completada_at IS NOT NULL AND NEW.expires_at IS NOT NULL)
              )
            BEGIN SELECT RAISE(ABORT, 'La actualización de exportación no es válida.'); END;

            CREATE TRIGGER exportacion_auditoria_edificios_insert_guard BEFORE INSERT ON exportacion_auditoria_edificios
            WHEN trim(NEW.nombre_snapshot) = '' OR trim(NEW.timezone_snapshot) = ''
              OR json_valid(NEW.permisos_fuente) = 0 OR json_type(NEW.permisos_fuente) <> 'array'
              OR NOT EXISTS (SELECT 1 FROM exportaciones_auditoria WHERE id = NEW.exportacion_id AND estado = 'pendiente')
              OR EXISTS (SELECT 1 FROM bitacora_exportacion_auditoria WHERE exportacion_id = NEW.exportacion_id)
            BEGIN SELECT RAISE(ABORT, 'El alcance de la exportación no tiene una forma válida.'); END;
            CREATE TRIGGER exportacion_auditoria_edificios_update_guard BEFORE UPDATE ON exportacion_auditoria_edificios
            BEGIN SELECT RAISE(ABORT, 'El alcance de la exportación es inmutable.'); END;
            CREATE TRIGGER exportacion_auditoria_edificios_delete_guard BEFORE DELETE ON exportacion_auditoria_edificios
            BEGIN SELECT RAISE(ABORT, 'El alcance de la exportación es inmutable.'); END;

            CREATE TRIGGER bitacora_exportacion_auditoria_insert_guard BEFORE INSERT ON bitacora_exportacion_auditoria
            WHEN NOT ((NEW.actor_tipo = 'usuario' AND NEW.actor_user_id IS NOT NULL)
              OR (NEW.actor_tipo = 'sistema' AND NEW.actor_user_id IS NULL))
            BEGIN SELECT RAISE(ABORT, 'El actor de la bitácora de exportación no es válido.'); END;

            CREATE TRIGGER bitacora_exportacion_auditoria_update_guard BEFORE UPDATE ON bitacora_exportacion_auditoria
            BEGIN SELECT RAISE(ABORT, 'La bitácora de exportaciones es inmutable.'); END;
            CREATE TRIGGER bitacora_exportacion_auditoria_delete_guard BEFORE DELETE ON bitacora_exportacion_auditoria
            BEGIN SELECT RAISE(ABORT, 'La bitácora de exportaciones es inmutable.'); END;
            SQL);
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
