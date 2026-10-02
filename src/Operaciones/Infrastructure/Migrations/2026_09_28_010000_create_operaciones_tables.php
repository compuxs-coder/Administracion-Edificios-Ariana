<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $edificios = $table('edificios');
        $memberships = $table('edificio_usuario');
        $residenteEdificio = $table('residente_edificio');
        $proveedorEdificio = $table('proveedor_edificio');
        $contratos = $table('contratos_proveedor');
        $torres = $table('torres');
        $pisos = $table('pisos');
        $departamentos = $table('departamentos');
        $parqueaderos = $table('parqueaderos');
        $bodegas = $table('bodegas');
        $consecutivos = $table('consecutivos_orden_operativa');
        $ordenes = $table('ordenes_operativas');
        $asignaciones = $table('asignaciones_orden_operativa');
        $evidencias = $table('evidencias_orden_operativa');
        $bitacora = $table('bitacora_orden_operativa');

        Schema::create($consecutivos, function (Blueprint $table): void {
            $table->unsignedSmallInteger('anio')->primary();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestampsTz();
        });

        Schema::create($ordenes, function (Blueprint $table) use (
            $edificios, $memberships, $residenteEdificio, $proveedorEdificio, $contratos,
            $torres, $pisos, $departamentos, $parqueaderos, $bodegas
        ): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->string('numero', 20)->unique('ordenes_operativas_numero_uq');
            $table->enum('tipo', ['incidencia', 'solicitud']);
            $table->string('titulo', 180);
            $table->text('descripcion');
            $table->enum('prioridad', ['baja', 'media', 'alta', 'critica']);
            $table->date('fecha_objetivo')->nullable();
            $table->enum('estado', ['reportada', 'en_revision', 'en_progreso', 'resuelta', 'cerrada', 'cancelada'])->default('reportada');
            $table->uuid('torre_id')->nullable();
            $table->uuid('piso_id')->nullable();
            $table->uuid('departamento_id')->nullable();
            $table->uuid('parqueadero_id')->nullable();
            $table->uuid('bodega_id')->nullable();
            $table->string('ubicacion_detalle', 500)->nullable();
            $table->uuid('reportante_residente_id')->nullable();
            $table->json('reportante_snapshot')->nullable();
            $table->uuid('proveedor_id')->nullable();
            $table->uuid('contrato_id')->nullable();
            $table->uuid('creada_por_user_id');
            $table->uuid('estado_actualizado_por')->nullable();
            $table->timestampTz('estado_actualizado_at')->nullable();
            $table->text('motivo_estado')->nullable();
            $table->timestampsTz();

            $table->unique(['edificio_id', 'id'], 'ordenes_operativas_scope_uq');
            $table->index(['edificio_id', 'estado', 'prioridad'], 'ordenes_operativas_estado_idx');
            $table->index(['edificio_id', 'fecha_objetivo'], 'ordenes_operativas_objetivo_idx');
            $table->foreign('edificio_id', 'ordenes_operativas_edificio_fk')->references('id')->on($edificios)->restrictOnDelete();
            $table->foreign(['edificio_id', 'creada_por_user_id'], 'ordenes_operativas_creador_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            $table->foreign(['edificio_id', 'estado_actualizado_por'], 'ordenes_operativas_estado_actor_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            $table->foreign(['reportante_residente_id', 'edificio_id'], 'ordenes_operativas_reportante_fk')
                ->references(['residente_id', 'edificio_id'])->on($residenteEdificio)->restrictOnDelete();
            $table->foreign(['edificio_id', 'proveedor_id'], 'ordenes_operativas_proveedor_fk')
                ->references(['edificio_id', 'proveedor_id'])->on($proveedorEdificio)->restrictOnDelete();
            $table->foreign(['edificio_id', 'contrato_id', 'proveedor_id'], 'ordenes_operativas_contrato_fk')
                ->references(['edificio_id', 'id', 'proveedor_id'])->on($contratos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'torre_id'], 'ordenes_operativas_torre_fk')->references(['edificio_id', 'id'])->on($torres)->restrictOnDelete();
            $table->foreign(['edificio_id', 'piso_id'], 'ordenes_operativas_piso_fk')->references(['edificio_id', 'id'])->on($pisos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'departamento_id'], 'ordenes_operativas_departamento_fk')->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'parqueadero_id'], 'ordenes_operativas_parqueadero_fk')->references(['edificio_id', 'id'])->on($parqueaderos)->restrictOnDelete();
            $table->foreign(['edificio_id', 'bodega_id'], 'ordenes_operativas_bodega_fk')->references(['edificio_id', 'id'])->on($bodegas)->restrictOnDelete();
        });

        Schema::create($asignaciones, function (Blueprint $table) use ($ordenes, $memberships, $proveedorEdificio): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('orden_operativa_id');
            $table->enum('tipo_responsable', ['usuario', 'proveedor']);
            $table->uuid('responsable_user_id')->nullable();
            $table->uuid('responsable_proveedor_id')->nullable();
            $table->json('responsable_snapshot');
            $table->uuid('asignado_por_user_id');
            $table->uuid('finalizado_por_user_id')->nullable();
            $table->timestampTz('fecha_inicio');
            $table->timestampTz('fecha_fin')->nullable();
            $table->index(['orden_operativa_id', 'fecha_inicio'], 'asignaciones_orden_fecha_idx');
            $table->foreign(['edificio_id', 'orden_operativa_id'], 'asignaciones_orden_fk')
                ->references(['edificio_id', 'id'])->on($ordenes)->restrictOnDelete();
            $table->foreign(['edificio_id', 'responsable_user_id'], 'asignaciones_miembro_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            $table->foreign(['edificio_id', 'responsable_proveedor_id'], 'asignaciones_proveedor_fk')
                ->references(['edificio_id', 'proveedor_id'])->on($proveedorEdificio)->restrictOnDelete();
            $table->foreign(['edificio_id', 'asignado_por_user_id'], 'asignaciones_actor_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
            $table->foreign(['edificio_id', 'finalizado_por_user_id'], 'asignaciones_finalizador_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });

        Schema::create($evidencias, function (Blueprint $table) use ($ordenes, $memberships): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('orden_operativa_id');
            $table->string('nombre_original', 220);
            $table->string('mime_type', 40);
            $table->unsignedBigInteger('tamano_bytes');
            $table->char('sha256', 64);
            $table->string('ruta_privada', 500)->unique('evidencias_orden_ruta_uq');
            $table->string('descripcion', 500)->nullable();
            $table->uuid('subido_por_user_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['orden_operativa_id', 'created_at'], 'evidencias_orden_fecha_idx');
            $table->foreign(['edificio_id', 'orden_operativa_id'], 'evidencias_orden_fk')
                ->references(['edificio_id', 'id'])->on($ordenes)->restrictOnDelete();
            $table->foreign(['edificio_id', 'subido_por_user_id'], 'evidencias_orden_actor_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });

        Schema::create($bitacora, function (Blueprint $table) use ($ordenes, $memberships): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('orden_operativa_id');
            $table->enum('tipo', ['creacion', 'cambio_datos', 'cambio_estado', 'asignacion', 'reasignacion', 'cancelacion', 'reapertura', 'evidencia', 'actuacion_manual']);
            $table->uuid('actor_user_id');
            $table->json('detalle')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['orden_operativa_id', 'created_at'], 'bitacora_orden_fecha_idx');
            $table->foreign(['edificio_id', 'orden_operativa_id'], 'bitacora_orden_fk')
                ->references(['edificio_id', 'id'])->on($ordenes)->restrictOnDelete();
            $table->foreign(['edificio_id', 'actor_user_id'], 'bitacora_orden_actor_fk')
                ->references(['edificio_id', 'user_id'])->on($memberships)->restrictOnDelete();
        });

        $prefix = $pgsql ? '"'.$schema.'".' : '';
        DB::statement("CREATE UNIQUE INDEX asignaciones_orden_actual_uq ON {$prefix}\"asignaciones_orden_operativa\" (\"orden_operativa_id\") WHERE \"fecha_fin\" IS NULL");
        $this->createGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        foreach (['ordenes_operativas', 'asignaciones_orden_operativa', 'evidencias_orden_operativa', 'bitacora_orden_operativa', 'consecutivos_orden_operativa'] as $historyTable) {
            if (DB::table($table($historyTable))->exists()) {
                throw new \RuntimeException('No se puede revertir Operaciones mientras exista historial operativo.');
            }
        }

        $this->dropGuards($pgsql, $schema);
        Schema::dropIfExists($table('bitacora_orden_operativa'));
        Schema::dropIfExists($table('evidencias_orden_operativa'));
        Schema::dropIfExists($table('asignaciones_orden_operativa'));
        Schema::dropIfExists($table('ordenes_operativas'));
        Schema::dropIfExists($table('consecutivos_orden_operativa'));
    }

    private function createGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            $this->createSqliteGuards();

            return;
        }

        DB::statement("ALTER TABLE \"{$schema}\".\"consecutivos_orden_operativa\" ADD CONSTRAINT consecutivos_orden_limite_check CHECK (ultimo_numero >= 0 AND ultimo_numero <= 999999)");
        DB::statement("ALTER TABLE \"{$schema}\".\"ordenes_operativas\" ADD CONSTRAINT ordenes_numero_formato_check CHECK (numero ~ '^OPR-[0-9]{4}-[0-9]{6}$')");
        DB::statement("ALTER TABLE \"{$schema}\".\"ordenes_operativas\" ADD CONSTRAINT ordenes_texto_check CHECK (btrim(titulo) <> '' AND btrim(descripcion) <> '')");
        DB::statement("ALTER TABLE \"{$schema}\".\"ordenes_operativas\" ADD CONSTRAINT ordenes_ubicacion_check CHECK (num_nonnulls(torre_id, piso_id, departamento_id, parqueadero_id, bodega_id) <= 1 AND (num_nonnulls(torre_id, piso_id, departamento_id, parqueadero_id, bodega_id) = 1 OR COALESCE(btrim(ubicacion_detalle), '') <> ''))");
        DB::statement("ALTER TABLE \"{$schema}\".\"ordenes_operativas\" ADD CONSTRAINT ordenes_reportante_snapshot_check CHECK ((reportante_residente_id IS NULL) = (reportante_snapshot IS NULL))");
        DB::statement("ALTER TABLE \"{$schema}\".\"ordenes_operativas\" ADD CONSTRAINT ordenes_contrato_proveedor_check CHECK (contrato_id IS NULL OR proveedor_id IS NOT NULL)");
        DB::statement("ALTER TABLE \"{$schema}\".\"evidencias_orden_operativa\" ADD CONSTRAINT evidencias_orden_tipo_check CHECK (mime_type IN ('application/pdf', 'image/jpeg', 'image/png'))");
        DB::statement("ALTER TABLE \"{$schema}\".\"evidencias_orden_operativa\" ADD CONSTRAINT evidencias_orden_tamano_check CHECK (tamano_bytes > 0 AND tamano_bytes <= 10485760)");
        DB::statement("ALTER TABLE \"{$schema}\".\"evidencias_orden_operativa\" ADD CONSTRAINT evidencias_orden_sha_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_consecutivo_orden_operativa"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El consecutivo operativo no puede eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF NEW.anio <> OLD.anio OR NEW.ultimo_numero < OLD.ultimo_numero THEN
                    RAISE EXCEPTION 'El consecutivo operativo no puede retroceder.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER consecutivos_orden_guard BEFORE UPDATE OR DELETE ON "{$schema}"."consecutivos_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_consecutivo_orden_operativa"();

            CREATE FUNCTION "{$schema}"."guard_orden_operativa"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE core_changed boolean;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las órdenes operativas no pueden eliminarse.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.estado <> 'reportada' OR NEW.estado_actualizado_por IS NOT NULL OR NEW.estado_actualizado_at IS NOT NULL OR NEW.motivo_estado IS NOT NULL THEN
                        RAISE EXCEPTION 'Una orden debe crearse en estado reportada.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.reportante_residente_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."residentes" r
                        JOIN "{$schema}"."residente_edificio" re ON re.residente_id = r.id
                        WHERE r.id = NEW.reportante_residente_id AND re.edificio_id = NEW.edificio_id AND r.estado = 'activo'
                    ) THEN
                        RAISE EXCEPTION 'El reportante debe ser un residente activo del edificio.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.proveedor_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."proveedor_edificio" pe
                        WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = NEW.proveedor_id AND pe.estado = 'activo'
                    ) THEN
                        RAISE EXCEPTION 'El proveedor asociado debe estar activo en el edificio.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.contrato_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."contratos_proveedor" c
                        WHERE c.id = NEW.contrato_id AND c.edificio_id = NEW.edificio_id AND c.proveedor_id = NEW.proveedor_id AND c.estado = 'registrado'
                    ) THEN
                        RAISE EXCEPTION 'El contrato debe estar registrado y corresponder al proveedor del edificio.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.reportante_residente_id IS NOT NULL AND NEW.reportante_residente_id IS DISTINCT FROM OLD.reportante_residente_id AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."residentes" r
                    JOIN "{$schema}"."residente_edificio" re ON re.residente_id = r.id
                    WHERE r.id = NEW.reportante_residente_id AND re.edificio_id = NEW.edificio_id AND r.estado = 'activo'
                ) THEN
                    RAISE EXCEPTION 'El reportante debe ser un residente activo del edificio.' USING ERRCODE = '23514';
                END IF;
                IF NEW.proveedor_id IS NOT NULL AND NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."proveedor_edificio" pe
                    WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = NEW.proveedor_id AND pe.estado = 'activo'
                ) THEN
                    RAISE EXCEPTION 'El proveedor asociado debe estar activo en el edificio.' USING ERRCODE = '23514';
                END IF;
                IF NEW.contrato_id IS NOT NULL AND (NEW.contrato_id IS DISTINCT FROM OLD.contrato_id OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id) AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."contratos_proveedor" c
                    WHERE c.id = NEW.contrato_id AND c.edificio_id = NEW.edificio_id AND c.proveedor_id = NEW.proveedor_id AND c.estado = 'registrado'
                ) THEN
                    RAISE EXCEPTION 'El contrato debe estar registrado y corresponder al proveedor del edificio.' USING ERRCODE = '23514';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                   OR NEW.numero IS DISTINCT FROM OLD.numero OR NEW.tipo IS DISTINCT FROM OLD.tipo
                   OR NEW.creada_por_user_id IS DISTINCT FROM OLD.creada_por_user_id
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'La identidad y el origen de la orden son inmutables.' USING ERRCODE = '23514';
                END IF;
                IF NEW.reportante_residente_id IS NOT DISTINCT FROM OLD.reportante_residente_id
                   AND NEW.reportante_snapshot::text IS DISTINCT FROM OLD.reportante_snapshot::text THEN
                    RAISE EXCEPTION 'El snapshot del reportante es inmutable.' USING ERRCODE = '23514';
                END IF;

                core_changed := NEW.titulo IS DISTINCT FROM OLD.titulo OR NEW.descripcion IS DISTINCT FROM OLD.descripcion
                    OR NEW.prioridad IS DISTINCT FROM OLD.prioridad
                    OR NEW.fecha_objetivo IS DISTINCT FROM OLD.fecha_objetivo OR NEW.torre_id IS DISTINCT FROM OLD.torre_id
                    OR NEW.piso_id IS DISTINCT FROM OLD.piso_id OR NEW.departamento_id IS DISTINCT FROM OLD.departamento_id
                    OR NEW.parqueadero_id IS DISTINCT FROM OLD.parqueadero_id OR NEW.bodega_id IS DISTINCT FROM OLD.bodega_id
                    OR NEW.ubicacion_detalle IS DISTINCT FROM OLD.ubicacion_detalle
                    OR NEW.reportante_residente_id IS DISTINCT FROM OLD.reportante_residente_id
                    OR NEW.reportante_snapshot::text IS DISTINCT FROM OLD.reportante_snapshot::text
                    OR NEW.proveedor_id IS DISTINCT FROM OLD.proveedor_id OR NEW.contrato_id IS DISTINCT FROM OLD.contrato_id;
                IF core_changed AND OLD.estado NOT IN ('reportada', 'en_revision', 'en_progreso') THEN
                    RAISE EXCEPTION 'Los datos centrales sólo pueden editarse en estados activos.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM "{$schema}"."asignaciones_orden_operativa" a WHERE a.orden_operativa_id = NEW.id AND a.fecha_fin IS NULL AND a.tipo_responsable = 'proveedor' AND a.responsable_proveedor_id IS DISTINCT FROM NEW.proveedor_id) THEN
                    RAISE EXCEPTION 'El proveedor responsable debe coincidir con el asociado a la orden.' USING ERRCODE = '23514';
                END IF;

                IF NEW.estado IS DISTINCT FROM OLD.estado THEN
                    IF NOT ((OLD.estado = 'reportada' AND NEW.estado IN ('en_revision', 'cancelada'))
                        OR (OLD.estado = 'en_revision' AND NEW.estado IN ('en_progreso', 'cancelada'))
                        OR (OLD.estado = 'en_progreso' AND NEW.estado IN ('resuelta', 'cancelada'))
                        OR (OLD.estado = 'resuelta' AND NEW.estado IN ('cerrada', 'en_progreso'))
                        OR (OLD.estado = 'cerrada' AND NEW.estado = 'en_progreso')) THEN
                        RAISE EXCEPTION 'La transición de estado no está permitida.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.estado_actualizado_por IS NULL OR NEW.estado_actualizado_at IS NULL THEN
                        RAISE EXCEPTION 'La transición requiere actor y fecha.' USING ERRCODE = '23514';
                    END IF;
                    IF (NEW.estado = 'cancelada' OR (OLD.estado IN ('resuelta', 'cerrada') AND NEW.estado = 'en_progreso'))
                       AND COALESCE(btrim(NEW.motivo_estado), '') = '' THEN
                        RAISE EXCEPTION 'La cancelación o reapertura requiere motivo.' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.estado IN ('en_progreso', 'resuelta', 'cerrada') AND NOT EXISTS (
                        SELECT 1 FROM "{$schema}"."asignaciones_orden_operativa" a
                        WHERE a.orden_operativa_id = NEW.id AND a.fecha_fin IS NULL AND (
                            (a.tipo_responsable = 'usuario' AND EXISTS (
                                SELECT 1 FROM "{$schema}"."edificio_usuario" eu
                                WHERE eu.edificio_id = NEW.edificio_id AND eu.user_id = a.responsable_user_id AND eu.revoked_at IS NULL
                            )) OR (a.tipo_responsable = 'proveedor' AND a.responsable_proveedor_id = NEW.proveedor_id AND EXISTS (
                                SELECT 1 FROM "{$schema}"."proveedor_edificio" pe
                                WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = a.responsable_proveedor_id AND pe.estado = 'activo'
                            ))
                        )
                    ) THEN
                        RAISE EXCEPTION 'La transición requiere un responsable vigente y activo.' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.estado_actualizado_por IS DISTINCT FROM OLD.estado_actualizado_por
                    OR NEW.estado_actualizado_at IS DISTINCT FROM OLD.estado_actualizado_at
                    OR NEW.motivo_estado IS DISTINCT FROM OLD.motivo_estado THEN
                    RAISE EXCEPTION 'La trazabilidad de estado sólo cambia durante una transición.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ordenes_operativas_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."ordenes_operativas"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_orden_operativa"();

            CREATE FUNCTION "{$schema}"."guard_asignacion_orden_operativa"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de responsables no puede eliminarse.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.fecha_fin IS NOT NULL OR NEW.fecha_fin IS NULL OR NEW.finalizado_por_user_id IS NULL OR NEW.fecha_fin < OLD.fecha_inicio
                       OR NEW.id IS DISTINCT FROM OLD.id OR NEW.edificio_id IS DISTINCT FROM OLD.edificio_id
                       OR NEW.orden_operativa_id IS DISTINCT FROM OLD.orden_operativa_id OR NEW.tipo_responsable IS DISTINCT FROM OLD.tipo_responsable
                       OR NEW.responsable_user_id IS DISTINCT FROM OLD.responsable_user_id
                       OR NEW.responsable_proveedor_id IS DISTINCT FROM OLD.responsable_proveedor_id
                       OR NEW.responsable_snapshot::text IS DISTINCT FROM OLD.responsable_snapshot::text
                       OR NEW.asignado_por_user_id IS DISTINCT FROM OLD.asignado_por_user_id OR NEW.fecha_inicio IS DISTINCT FROM OLD.fecha_inicio THEN
                        RAISE EXCEPTION 'Una asignación sólo puede cerrarse una vez.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.fecha_fin IS NOT NULL OR NEW.finalizado_por_user_id IS NOT NULL OR NEW.responsable_snapshot IS NULL
                   OR (NEW.tipo_responsable = 'usuario' AND (NEW.responsable_user_id IS NULL OR NEW.responsable_proveedor_id IS NOT NULL))
                   OR (NEW.tipo_responsable = 'proveedor' AND (NEW.responsable_proveedor_id IS NULL OR NEW.responsable_user_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'La asignación vigente tiene una forma inválida.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM "{$schema}"."ordenes_operativas" o WHERE o.id = NEW.orden_operativa_id AND o.estado = 'cancelada') THEN
                    RAISE EXCEPTION 'Una orden cancelada no admite responsables.' USING ERRCODE = '23514';
                END IF;
                IF NEW.tipo_responsable = 'usuario' AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."edificio_usuario" eu WHERE eu.edificio_id = NEW.edificio_id AND eu.user_id = NEW.responsable_user_id AND eu.revoked_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'El responsable interno debe ser miembro activo.' USING ERRCODE = '23514';
                END IF;
                IF NEW.tipo_responsable = 'proveedor' AND NOT EXISTS (
                    SELECT 1 FROM "{$schema}"."proveedor_edificio" pe
                    JOIN "{$schema}"."ordenes_operativas" o ON o.id = NEW.orden_operativa_id AND o.edificio_id = NEW.edificio_id
                    WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = NEW.responsable_proveedor_id
                      AND pe.estado = 'activo' AND o.proveedor_id = NEW.responsable_proveedor_id
                ) THEN
                    RAISE EXCEPTION 'El proveedor responsable debe estar activo y asociado a la orden.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER asignaciones_orden_guard BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}"."asignaciones_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_asignacion_orden_operativa"();

            CREATE FUNCTION "{$schema}"."guard_evidencia_orden_insert"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM "{$schema}"."ordenes_operativas" o
                    WHERE o.id = NEW.orden_operativa_id AND o.edificio_id = NEW.edificio_id
                      AND o.estado IN ('cerrada', 'cancelada')
                ) THEN
                    RAISE EXCEPTION 'Una orden cerrada o cancelada no admite evidencias.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER evidencias_orden_insert_guard BEFORE INSERT ON "{$schema}"."evidencias_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_evidencia_orden_insert"();

            CREATE FUNCTION "{$schema}"."guard_bitacora_orden_insert"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.tipo IN ('actuacion_manual', 'evidencia') AND EXISTS (
                    SELECT 1 FROM "{$schema}"."ordenes_operativas" o
                    WHERE o.id = NEW.orden_operativa_id AND o.edificio_id = NEW.edificio_id
                      AND o.estado IN ('cerrada', 'cancelada')
                ) THEN
                    RAISE EXCEPTION 'Una orden cerrada o cancelada no admite actuaciones ni evidencias.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER bitacora_orden_insert_guard BEFORE INSERT ON "{$schema}"."bitacora_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_bitacora_orden_insert"();

            CREATE FUNCTION "{$schema}"."prevent_operaciones_history_mutation"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'El historial operativo es inmutable.' USING ERRCODE = '23514';
            END;
            \$\$;
            CREATE TRIGGER evidencias_orden_guard BEFORE UPDATE OR DELETE ON "{$schema}"."evidencias_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_operaciones_history_mutation"();
            CREATE TRIGGER bitacora_orden_guard BEFORE UPDATE OR DELETE ON "{$schema}"."bitacora_orden_operativa"
            FOR EACH ROW EXECUTE FUNCTION "{$schema}"."prevent_operaciones_history_mutation"();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ordenes_operativas_insert_guard BEFORE INSERT ON ordenes_operativas
            WHEN NEW.numero NOT GLOB 'OPR-[0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]'
              OR trim(NEW.titulo) = '' OR trim(NEW.descripcion) = '' OR NEW.estado <> 'reportada'
              OR NEW.estado_actualizado_por IS NOT NULL OR NEW.estado_actualizado_at IS NOT NULL OR NEW.motivo_estado IS NOT NULL
              OR ((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) > 1
              OR (((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) = 0 AND trim(COALESCE(NEW.ubicacion_detalle, '')) = '')
              OR ((NEW.reportante_residente_id IS NULL) <> (NEW.reportante_snapshot IS NULL))
              OR (NEW.contrato_id IS NOT NULL AND NEW.proveedor_id IS NULL)
            BEGIN SELECT RAISE(ABORT, 'La orden operativa inicial tiene una forma inválida.'); END;

            CREATE TRIGGER ordenes_reportante_insert_guard BEFORE INSERT ON ordenes_operativas
            WHEN NEW.reportante_residente_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM residentes r JOIN residente_edificio re ON re.residente_id = r.id
                WHERE r.id = NEW.reportante_residente_id AND re.edificio_id = NEW.edificio_id AND r.estado = 'activo'
            ) BEGIN SELECT RAISE(ABORT, 'El reportante debe ser un residente activo del edificio.'); END;

            CREATE TRIGGER ordenes_proveedor_insert_guard BEFORE INSERT ON ordenes_operativas
            WHEN NEW.proveedor_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM proveedor_edificio pe WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = NEW.proveedor_id AND pe.estado = 'activo'
            ) BEGIN SELECT RAISE(ABORT, 'El proveedor asociado debe estar activo en el edificio.'); END;

            CREATE TRIGGER ordenes_contrato_insert_guard BEFORE INSERT ON ordenes_operativas
            WHEN NEW.contrato_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM contratos_proveedor c WHERE c.id = NEW.contrato_id AND c.edificio_id = NEW.edificio_id AND c.proveedor_id = NEW.proveedor_id AND c.estado = 'registrado'
            ) BEGIN SELECT RAISE(ABORT, 'El contrato debe estar registrado y corresponder al proveedor del edificio.'); END;

            CREATE TRIGGER ordenes_operativas_delete_guard BEFORE DELETE ON ordenes_operativas
            BEGIN SELECT RAISE(ABORT, 'Las órdenes operativas no pueden eliminarse.'); END;

            CREATE TRIGGER ordenes_operativas_identity_guard BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.numero IS NOT OLD.numero
              OR NEW.tipo IS NOT OLD.tipo OR NEW.creada_por_user_id IS NOT OLD.creada_por_user_id OR NEW.created_at IS NOT OLD.created_at
            BEGIN SELECT RAISE(ABORT, 'La identidad y el origen de la orden son inmutables.'); END;

            CREATE TRIGGER ordenes_operativas_shape_update_guard BEFORE UPDATE ON ordenes_operativas
            WHEN trim(NEW.titulo) = '' OR trim(NEW.descripcion) = ''
              OR ((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) > 1
              OR (((NEW.torre_id IS NOT NULL) + (NEW.piso_id IS NOT NULL) + (NEW.departamento_id IS NOT NULL) + (NEW.parqueadero_id IS NOT NULL) + (NEW.bodega_id IS NOT NULL)) = 0 AND trim(COALESCE(NEW.ubicacion_detalle, '')) = '')
              OR ((NEW.reportante_residente_id IS NULL) <> (NEW.reportante_snapshot IS NULL))
              OR (NEW.contrato_id IS NOT NULL AND NEW.proveedor_id IS NULL)
            BEGIN SELECT RAISE(ABORT, 'Los datos centrales de la orden tienen una forma inválida.'); END;

            CREATE TRIGGER ordenes_reportante_snapshot_guard BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.reportante_residente_id IS OLD.reportante_residente_id AND NEW.reportante_snapshot IS NOT OLD.reportante_snapshot
            BEGIN SELECT RAISE(ABORT, 'El snapshot del reportante es inmutable.'); END;

            CREATE TRIGGER ordenes_reportante_update_guard BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.reportante_residente_id IS NOT OLD.reportante_residente_id AND NEW.reportante_residente_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM residentes r JOIN residente_edificio re ON re.residente_id = r.id
                WHERE r.id = NEW.reportante_residente_id AND re.edificio_id = NEW.edificio_id AND r.estado = 'activo'
            ) BEGIN SELECT RAISE(ABORT, 'El reportante debe ser un residente activo del edificio.'); END;

            CREATE TRIGGER ordenes_proveedor_update_guard BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.proveedor_id IS NOT OLD.proveedor_id AND NEW.proveedor_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM proveedor_edificio pe WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = NEW.proveedor_id AND pe.estado = 'activo'
            ) BEGIN SELECT RAISE(ABORT, 'El proveedor asociado debe estar activo en el edificio.'); END;

            CREATE TRIGGER ordenes_contrato_update_guard BEFORE UPDATE ON ordenes_operativas
            WHEN (NEW.contrato_id IS NOT OLD.contrato_id OR NEW.proveedor_id IS NOT OLD.proveedor_id) AND NEW.contrato_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM contratos_proveedor c WHERE c.id = NEW.contrato_id AND c.edificio_id = NEW.edificio_id AND c.proveedor_id = NEW.proveedor_id AND c.estado = 'registrado'
            ) BEGIN SELECT RAISE(ABORT, 'El contrato debe estar registrado y corresponder al proveedor del edificio.'); END;

            CREATE TRIGGER ordenes_core_terminal_guard BEFORE UPDATE ON ordenes_operativas
            WHEN OLD.estado NOT IN ('reportada', 'en_revision', 'en_progreso') AND (
                NEW.titulo IS NOT OLD.titulo OR NEW.descripcion IS NOT OLD.descripcion OR NEW.prioridad IS NOT OLD.prioridad
                OR NEW.fecha_objetivo IS NOT OLD.fecha_objetivo
                OR NEW.torre_id IS NOT OLD.torre_id OR NEW.piso_id IS NOT OLD.piso_id OR NEW.departamento_id IS NOT OLD.departamento_id
                OR NEW.parqueadero_id IS NOT OLD.parqueadero_id OR NEW.bodega_id IS NOT OLD.bodega_id
                OR NEW.ubicacion_detalle IS NOT OLD.ubicacion_detalle OR NEW.reportante_residente_id IS NOT OLD.reportante_residente_id
                OR NEW.reportante_snapshot IS NOT OLD.reportante_snapshot OR NEW.proveedor_id IS NOT OLD.proveedor_id OR NEW.contrato_id IS NOT OLD.contrato_id
            ) BEGIN SELECT RAISE(ABORT, 'Los datos centrales sólo pueden editarse en estados activos.'); END;

            CREATE TRIGGER ordenes_proveedor_responsable_guard BEFORE UPDATE OF proveedor_id ON ordenes_operativas
            WHEN EXISTS (
                SELECT 1 FROM asignaciones_orden_operativa a WHERE a.orden_operativa_id = NEW.id AND a.fecha_fin IS NULL
                  AND a.tipo_responsable = 'proveedor' AND a.responsable_proveedor_id IS NOT NEW.proveedor_id
            ) BEGIN SELECT RAISE(ABORT, 'El proveedor responsable debe coincidir con el asociado a la orden.'); END;

            CREATE TRIGGER ordenes_estado_guard BEFORE UPDATE OF estado ON ordenes_operativas
            WHEN NEW.estado IS NOT OLD.estado AND (
                NOT ((OLD.estado = 'reportada' AND NEW.estado IN ('en_revision', 'cancelada'))
                  OR (OLD.estado = 'en_revision' AND NEW.estado IN ('en_progreso', 'cancelada'))
                  OR (OLD.estado = 'en_progreso' AND NEW.estado IN ('resuelta', 'cancelada'))
                  OR (OLD.estado = 'resuelta' AND NEW.estado IN ('cerrada', 'en_progreso'))
                  OR (OLD.estado = 'cerrada' AND NEW.estado = 'en_progreso'))
                OR NEW.estado_actualizado_por IS NULL OR NEW.estado_actualizado_at IS NULL
                OR ((NEW.estado = 'cancelada' OR (OLD.estado IN ('resuelta', 'cerrada') AND NEW.estado = 'en_progreso')) AND trim(COALESCE(NEW.motivo_estado, '')) = '')
            ) BEGIN SELECT RAISE(ABORT, 'La transición de estado no está permitida o carece de trazabilidad.'); END;

            CREATE TRIGGER ordenes_estado_audit_guard BEFORE UPDATE ON ordenes_operativas
            WHEN NEW.estado IS OLD.estado AND (NEW.estado_actualizado_por IS NOT OLD.estado_actualizado_por OR NEW.estado_actualizado_at IS NOT OLD.estado_actualizado_at OR NEW.motivo_estado IS NOT OLD.motivo_estado)
            BEGIN SELECT RAISE(ABORT, 'La trazabilidad de estado sólo cambia durante una transición.'); END;

            CREATE TRIGGER ordenes_responsable_transicion_guard BEFORE UPDATE OF estado ON ordenes_operativas
            WHEN NEW.estado IS NOT OLD.estado AND NEW.estado IN ('en_progreso', 'resuelta', 'cerrada') AND NOT EXISTS (
                SELECT 1 FROM asignaciones_orden_operativa a
                WHERE a.orden_operativa_id = NEW.id AND a.fecha_fin IS NULL AND (
                    (a.tipo_responsable = 'usuario' AND EXISTS (
                        SELECT 1 FROM edificio_usuario eu WHERE eu.edificio_id = NEW.edificio_id AND eu.user_id = a.responsable_user_id AND eu.revoked_at IS NULL
                    )) OR (a.tipo_responsable = 'proveedor' AND a.responsable_proveedor_id = NEW.proveedor_id AND EXISTS (
                        SELECT 1 FROM proveedor_edificio pe WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = a.responsable_proveedor_id AND pe.estado = 'activo'
                    ))
                )
            ) BEGIN SELECT RAISE(ABORT, 'La transición requiere un responsable vigente y activo.'); END;

            CREATE TRIGGER asignaciones_insert_shape_guard BEFORE INSERT ON asignaciones_orden_operativa
            WHEN NEW.fecha_fin IS NOT NULL OR NEW.finalizado_por_user_id IS NOT NULL OR NEW.responsable_snapshot IS NULL
              OR (NEW.tipo_responsable = 'usuario' AND (NEW.responsable_user_id IS NULL OR NEW.responsable_proveedor_id IS NOT NULL))
              OR (NEW.tipo_responsable = 'proveedor' AND (NEW.responsable_proveedor_id IS NULL OR NEW.responsable_user_id IS NOT NULL))
              OR EXISTS (SELECT 1 FROM ordenes_operativas o WHERE o.id = NEW.orden_operativa_id AND o.estado = 'cancelada')
            BEGIN SELECT RAISE(ABORT, 'La asignación vigente tiene una forma inválida.'); END;

            CREATE TRIGGER asignaciones_miembro_guard BEFORE INSERT ON asignaciones_orden_operativa
            WHEN NEW.tipo_responsable = 'usuario' AND NOT EXISTS (
                SELECT 1 FROM edificio_usuario eu WHERE eu.edificio_id = NEW.edificio_id AND eu.user_id = NEW.responsable_user_id AND eu.revoked_at IS NULL
            ) BEGIN SELECT RAISE(ABORT, 'El responsable interno debe ser miembro activo.'); END;

            CREATE TRIGGER asignaciones_proveedor_guard BEFORE INSERT ON asignaciones_orden_operativa
            WHEN NEW.tipo_responsable = 'proveedor' AND NOT EXISTS (
                SELECT 1 FROM proveedor_edificio pe JOIN ordenes_operativas o ON o.id = NEW.orden_operativa_id AND o.edificio_id = NEW.edificio_id
                WHERE pe.edificio_id = NEW.edificio_id AND pe.proveedor_id = NEW.responsable_proveedor_id AND pe.estado = 'activo' AND o.proveedor_id = NEW.responsable_proveedor_id
            ) BEGIN SELECT RAISE(ABORT, 'El proveedor responsable debe estar activo y asociado a la orden.'); END;

            CREATE TRIGGER asignaciones_update_guard BEFORE UPDATE ON asignaciones_orden_operativa
            WHEN OLD.fecha_fin IS NOT NULL OR NEW.fecha_fin IS NULL OR NEW.finalizado_por_user_id IS NULL OR NEW.fecha_fin < OLD.fecha_inicio
              OR NEW.id IS NOT OLD.id OR NEW.edificio_id IS NOT OLD.edificio_id OR NEW.orden_operativa_id IS NOT OLD.orden_operativa_id
              OR NEW.tipo_responsable IS NOT OLD.tipo_responsable OR NEW.responsable_user_id IS NOT OLD.responsable_user_id
              OR NEW.responsable_proveedor_id IS NOT OLD.responsable_proveedor_id OR NEW.responsable_snapshot IS NOT OLD.responsable_snapshot
              OR NEW.asignado_por_user_id IS NOT OLD.asignado_por_user_id OR NEW.fecha_inicio IS NOT OLD.fecha_inicio
            BEGIN SELECT RAISE(ABORT, 'Una asignación sólo puede cerrarse una vez.'); END;

            CREATE TRIGGER asignaciones_delete_guard BEFORE DELETE ON asignaciones_orden_operativa
            BEGIN SELECT RAISE(ABORT, 'El historial de responsables no puede eliminarse.'); END;

            CREATE TRIGGER consecutivos_orden_insert_guard BEFORE INSERT ON consecutivos_orden_operativa
            WHEN NEW.ultimo_numero < 0 OR NEW.ultimo_numero > 999999
            BEGIN SELECT RAISE(ABORT, 'El consecutivo operativo está fuera de rango.'); END;
            CREATE TRIGGER consecutivos_orden_update_guard BEFORE UPDATE ON consecutivos_orden_operativa
            WHEN NEW.anio <> OLD.anio OR NEW.ultimo_numero < OLD.ultimo_numero OR NEW.ultimo_numero > 999999
            BEGIN SELECT RAISE(ABORT, 'El consecutivo operativo no puede retroceder ni exceder su rango.'); END;
            CREATE TRIGGER consecutivos_orden_delete_guard BEFORE DELETE ON consecutivos_orden_operativa
            BEGIN SELECT RAISE(ABORT, 'El consecutivo operativo no puede eliminarse.'); END;

            CREATE TRIGGER evidencias_orden_insert_guard BEFORE INSERT ON evidencias_orden_operativa
            WHEN NEW.mime_type NOT IN ('application/pdf', 'image/jpeg', 'image/png')
              OR NEW.tamano_bytes <= 0 OR NEW.tamano_bytes > 10485760
              OR length(NEW.sha256) <> 64 OR NEW.sha256 GLOB '*[^0-9a-f]*'
            BEGIN SELECT RAISE(ABORT, 'La evidencia operativa tiene metadatos inválidos.'); END;
            CREATE TRIGGER evidencias_orden_estado_insert_guard BEFORE INSERT ON evidencias_orden_operativa
            WHEN EXISTS (
                SELECT 1 FROM ordenes_operativas o
                WHERE o.id = NEW.orden_operativa_id AND o.edificio_id = NEW.edificio_id
                  AND o.estado IN ('cerrada', 'cancelada')
            ) BEGIN SELECT RAISE(ABORT, 'Una orden cerrada o cancelada no admite evidencias.'); END;
            CREATE TRIGGER evidencias_orden_update_guard BEFORE UPDATE ON evidencias_orden_operativa
            BEGIN SELECT RAISE(ABORT, 'Las evidencias operativas son inmutables.'); END;
            CREATE TRIGGER evidencias_orden_delete_guard BEFORE DELETE ON evidencias_orden_operativa
            BEGIN SELECT RAISE(ABORT, 'Las evidencias operativas no pueden eliminarse.'); END;
            CREATE TRIGGER bitacora_orden_estado_insert_guard BEFORE INSERT ON bitacora_orden_operativa
            WHEN NEW.tipo IN ('actuacion_manual', 'evidencia') AND EXISTS (
                SELECT 1 FROM ordenes_operativas o
                WHERE o.id = NEW.orden_operativa_id AND o.edificio_id = NEW.edificio_id
                  AND o.estado IN ('cerrada', 'cancelada')
            ) BEGIN SELECT RAISE(ABORT, 'Una orden cerrada o cancelada no admite actuaciones ni evidencias.'); END;
            CREATE TRIGGER bitacora_orden_update_guard BEFORE UPDATE ON bitacora_orden_operativa
            BEGIN SELECT RAISE(ABORT, 'La bitácora operativa es inmutable.'); END;
            CREATE TRIGGER bitacora_orden_delete_guard BEFORE DELETE ON bitacora_orden_operativa
            BEGIN SELECT RAISE(ABORT, 'La bitácora operativa no puede eliminarse.'); END;
            SQL);
    }

    private function dropGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach ([
                'ordenes_operativas_insert_guard', 'ordenes_reportante_insert_guard', 'ordenes_proveedor_insert_guard',
                'ordenes_contrato_insert_guard', 'ordenes_operativas_delete_guard', 'ordenes_operativas_identity_guard',
                'ordenes_operativas_shape_update_guard', 'ordenes_reportante_snapshot_guard', 'ordenes_reportante_update_guard',
                'ordenes_proveedor_update_guard', 'ordenes_contrato_update_guard', 'ordenes_core_terminal_guard',
                'ordenes_proveedor_responsable_guard', 'ordenes_estado_guard', 'ordenes_estado_audit_guard',
                'ordenes_responsable_transicion_guard', 'asignaciones_insert_shape_guard', 'asignaciones_miembro_guard',
                'asignaciones_proveedor_guard', 'asignaciones_update_guard', 'asignaciones_delete_guard',
                'consecutivos_orden_insert_guard', 'consecutivos_orden_update_guard', 'consecutivos_orden_delete_guard', 'evidencias_orden_insert_guard',
                'evidencias_orden_estado_insert_guard', 'bitacora_orden_estado_insert_guard',
                'evidencias_orden_update_guard', 'evidencias_orden_delete_guard', 'bitacora_orden_update_guard',
                'bitacora_orden_delete_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS bitacora_orden_guard ON "{$schema}"."bitacora_orden_operativa";
            DROP TRIGGER IF EXISTS evidencias_orden_guard ON "{$schema}"."evidencias_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."prevent_operaciones_history_mutation"();
            DROP TRIGGER IF EXISTS bitacora_orden_insert_guard ON "{$schema}"."bitacora_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_bitacora_orden_insert"();
            DROP TRIGGER IF EXISTS evidencias_orden_insert_guard ON "{$schema}"."evidencias_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_evidencia_orden_insert"();
            DROP TRIGGER IF EXISTS asignaciones_orden_guard ON "{$schema}"."asignaciones_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_asignacion_orden_operativa"();
            DROP TRIGGER IF EXISTS ordenes_operativas_guard ON "{$schema}"."ordenes_operativas";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_orden_operativa"();
            DROP TRIGGER IF EXISTS consecutivos_orden_guard ON "{$schema}"."consecutivos_orden_operativa";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_consecutivo_orden_operativa"();
            SQL);
    }
};
