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

        $terceros = $table('terceros');
        $propietarios = $table('propietarios');
        $residentes = $table('residentes');
        $residenteEdificio = $table('residente_edificio');
        $ocupaciones = $table('departamento_residentes');
        $edificios = $table('edificios');
        $departamentos = $table('departamentos');

        Schema::create($terceros, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('tipo_persona', ['persona_natural', 'persona_juridica']);
            $table->string('nombres', 120)->nullable();
            $table->string('apellidos', 120)->nullable();
            $table->string('razon_social', 180)->nullable();
            $table->enum('tipo_identificacion', ['cedula', 'ruc', 'pasaporte', 'otro']);
            $table->string('identificacion', 40);
            $table->string('telefono', 30)->nullable();
            $table->string('celular', 30)->nullable();
            $table->string('correo', 255)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['tipo_identificacion', 'identificacion'], 'terceros_identificacion_uq');
            $table->index(['tipo_persona', 'apellidos'], 'terceros_tipo_apellidos_idx');
            $table->index('razon_social', 'terceros_razon_social_idx');
        });

        Schema::table($propietarios, function (Blueprint $table) use ($terceros): void {
            $table->uuid('tercero_id')->nullable();
            $table->unique('tercero_id', 'propietarios_tercero_uq');
            $table->foreign('tercero_id', 'propietarios_tercero_fk')
                ->references('id')->on($terceros)->restrictOnDelete();
        });

        DB::table($propietarios)
            ->orderBy('id')
            ->each(function (object $propietario) use ($terceros, $propietarios): void {
                DB::table($terceros)->insert([
                    'id' => $propietario->id,
                    'tipo_persona' => $propietario->tipo_persona,
                    'nombres' => $propietario->nombres,
                    'apellidos' => $propietario->apellidos,
                    'razon_social' => $propietario->razon_social,
                    'tipo_identificacion' => $propietario->tipo_identificacion,
                    'identificacion' => $propietario->identificacion,
                    'telefono' => $propietario->telefono,
                    'celular' => $propietario->celular,
                    'correo' => $propietario->correo,
                    'direccion' => $propietario->direccion,
                    'created_at' => $propietario->created_at,
                    'updated_at' => $propietario->updated_at,
                ]);
                DB::table($propietarios)->where('id', $propietario->id)->update(['tercero_id' => $propietario->id]);
            });

        if ($pgsql) {
            DB::statement("ALTER TABLE \"{$schema}\".\"propietarios\" ALTER COLUMN tercero_id SET NOT NULL");
        }

        Schema::create($residentes, function (Blueprint $table) use ($terceros): void {
            $table->uuid('id')->primary();
            $table->uuid('tercero_id')->unique('residentes_tercero_uq');
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->index('estado', 'residentes_estado_idx');
            $table->foreign('tercero_id', 'residentes_tercero_fk')
                ->references('id')->on($terceros)->restrictOnDelete();
        });

        Schema::create($residenteEdificio, function (Blueprint $table) use ($residentes, $edificios): void {
            $table->uuid('residente_id');
            $table->uuid('edificio_id');
            $table->timestampsTz();

            $table->primary(['residente_id', 'edificio_id'], 'residente_edificio_pk');
            $table->index('edificio_id', 'residente_edificio_edificio_idx');
            $table->foreign('residente_id', 'residente_edificio_residente_fk')
                ->references('id')->on($residentes)->restrictOnDelete();
            $table->foreign('edificio_id', 'residente_edificio_edificio_fk')
                ->references('id')->on($edificios)->restrictOnDelete();
        });

        Schema::create($ocupaciones, function (Blueprint $table) use ($residentes, $residenteEdificio, $departamentos): void {
            $table->uuid('id')->primary();
            $table->uuid('edificio_id');
            $table->uuid('departamento_id');
            $table->uuid('residente_id');
            $table->string('nombre_residente', 250);
            $table->string('tipo_identificacion_snapshot', 30);
            $table->string('identificacion_snapshot', 40);
            $table->enum('tipo_ocupacion', ['propietario_ocupante', 'arrendatario', 'otro']);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->enum('estado', ['activa', 'finalizada'])->default('activa');
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->index(['edificio_id', 'departamento_id', 'estado'], 'ocupaciones_departamento_estado_idx');
            $table->index(['residente_id', 'estado'], 'ocupaciones_residente_estado_idx');
            $table->index(['departamento_id', 'residente_id', 'fecha_inicio'], 'ocupaciones_historial_idx');
            $table->foreign(['edificio_id', 'departamento_id'], 'ocupaciones_departamento_fk')
                ->references(['edificio_id', 'id'])->on($departamentos)->restrictOnDelete();
            $table->foreign('residente_id', 'ocupaciones_residente_fk')
                ->references('id')->on($residentes)->restrictOnDelete();
            $table->foreign(['residente_id', 'edificio_id'], 'ocupaciones_residente_edificio_fk')
                ->references(['residente_id', 'edificio_id'])->on($residenteEdificio)->restrictOnDelete();
        });

        $prefix = $pgsql ? '"'.$schema.'".' : '';
        DB::statement("CREATE UNIQUE INDEX ocupaciones_activas_pareja_uq ON {$prefix}\"departamento_residentes\" (\"departamento_id\", \"residente_id\") WHERE \"fecha_fin\" IS NULL");

        $this->createIntegrityGuards($pgsql, $schema);
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        $this->dropIntegrityGuards($pgsql, $schema);
        Schema::dropIfExists($table('departamento_residentes'));
        Schema::dropIfExists($table('residente_edificio'));
        Schema::dropIfExists($table('residentes'));

        Schema::table($table('propietarios'), function (Blueprint $table): void {
            $table->dropForeign('propietarios_tercero_fk');
            $table->dropUnique('propietarios_tercero_uq');
            $table->dropColumn('tercero_id');
        });

        Schema::dropIfExists($table('terceros'));
    }

    private function createIntegrityGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            $this->createSqliteGuards();

            return;
        }

        DB::statement("ALTER TABLE \"{$schema}\".\"terceros\" ADD CONSTRAINT terceros_nombre_check CHECK ((tipo_persona = 'persona_natural' AND nombres IS NOT NULL AND btrim(nombres) <> '' AND apellidos IS NOT NULL AND btrim(apellidos) <> '' AND razon_social IS NULL) OR (tipo_persona = 'persona_juridica' AND razon_social IS NOT NULL AND btrim(razon_social) <> '' AND nombres IS NULL AND apellidos IS NULL))");
        DB::statement("ALTER TABLE \"{$schema}\".\"departamento_residentes\" ADD CONSTRAINT ocupaciones_fechas_check CHECK (fecha_fin IS NULL OR fecha_fin > fecha_inicio)");
        DB::statement("ALTER TABLE \"{$schema}\".\"departamento_residentes\" ADD CONSTRAINT ocupaciones_estado_fecha_check CHECK ((estado = 'activa' AND fecha_fin IS NULL) OR (estado = 'finalizada' AND fecha_fin IS NOT NULL))");

        DB::unprepared(<<<SQL
            CREATE FUNCTION "{$schema}"."guard_residente_natural"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM "{$schema}"."terceros" WHERE id = NEW.tercero_id AND tipo_persona = 'persona_natural') THEN
                    RAISE EXCEPTION 'Un residente debe corresponder a una persona natural.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER residentes_natural_guard BEFORE INSERT OR UPDATE OF tercero_id
            ON "{$schema}"."residentes" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_residente_natural"();

            CREATE FUNCTION "{$schema}"."guard_tercero_residente_natural"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.tipo_persona <> 'persona_natural' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."residentes" WHERE tercero_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Una identidad con perfil de residente debe permanecer como persona natural.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER terceros_residente_natural_guard BEFORE UPDATE OF tipo_persona
            ON "{$schema}"."terceros" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_tercero_residente_natural"();

            CREATE FUNCTION "{$schema}"."guard_residente_history"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los residentes no pueden eliminarse; utilice el estado.' USING ERRCODE = '23514';
                END IF;
                IF NEW.estado = 'inactivo' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."departamento_residentes"
                    WHERE residente_id = NEW.id AND estado = 'activa'
                ) THEN
                    RAISE EXCEPTION 'No se puede inactivar un residente con ocupaciones activas.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER residentes_history_guard BEFORE UPDATE OF estado OR DELETE
            ON "{$schema}"."residentes" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_residente_history"();

            CREATE FUNCTION "{$schema}"."guard_departamento_ocupacion"()
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
                IF NEW.estado = 'activa' AND NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."residentes" r
                    JOIN "{$schema}"."departamentos" d ON d.id = NEW.departamento_id AND d.edificio_id = NEW.edificio_id
                    JOIN "{$schema}"."edificios" e ON e.id = NEW.edificio_id
                    WHERE r.id = NEW.residente_id
                      AND r.estado = 'activo'
                      AND d.estado = 'activo'
                      AND e.estado = 'activo'
                ) THEN
                    RAISE EXCEPTION 'Una ocupación activa requiere edificio, departamento y residente activos.' USING ERRCODE = '23514';
                END IF;
                PERFORM 1 FROM "{$schema}"."departamentos" WHERE id = NEW.departamento_id FOR UPDATE;
                IF EXISTS (
                    SELECT 1 FROM "{$schema}"."departamento_residentes" existing
                    WHERE existing.departamento_id = NEW.departamento_id
                      AND existing.residente_id = NEW.residente_id
                      AND existing.id <> NEW.id
                      AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, 'infinity'::date)
                      AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, 'infinity'::date)
                ) THEN
                    RAISE EXCEPTION 'La ocupación se superpone con el historial existente.' USING ERRCODE = '23514';
                END IF;
                IF NEW.tipo_ocupacion = 'propietario_ocupante' AND NOT EXISTS (
                    SELECT 1
                    FROM "{$schema}"."residentes" r
                    JOIN "{$schema}"."propietarios" p ON p.tercero_id = r.tercero_id
                    JOIN "{$schema}"."departamento_propietarios" t ON t.propietario_id = p.id
                    WHERE r.id = NEW.residente_id
                      AND t.departamento_id = NEW.departamento_id
                      AND t.fecha_inicio <= NEW.fecha_inicio
                      AND (t.fecha_fin IS NULL OR t.fecha_fin > NEW.fecha_inicio)
                ) THEN
                    RAISE EXCEPTION 'El propietario ocupante requiere una titularidad vigente al inicio.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER ocupaciones_integridad_guard BEFORE INSERT OR UPDATE OR DELETE
            ON "{$schema}"."departamento_residentes" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_departamento_ocupacion"();

            CREATE FUNCTION "{$schema}"."guard_departamento_ocupado"()
            RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.estado = 'inactivo' AND EXISTS (
                    SELECT 1 FROM "{$schema}"."departamento_residentes"
                    WHERE departamento_id = NEW.id AND estado = 'activa'
                ) THEN
                    RAISE EXCEPTION 'No se puede inactivar un departamento con ocupaciones activas.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            CREATE TRIGGER departamentos_ocupacion_guard BEFORE UPDATE OF estado
            ON "{$schema}"."departamentos" FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_departamento_ocupado"();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER residentes_natural_guard
            BEFORE INSERT ON residentes
            WHEN NOT EXISTS (SELECT 1 FROM terceros WHERE id = NEW.tercero_id AND tipo_persona = 'persona_natural')
            BEGIN SELECT RAISE(ABORT, 'Un residente debe corresponder a una persona natural.'); END;

            CREATE TRIGGER residentes_natural_update_guard
            BEFORE UPDATE OF tercero_id ON residentes
            WHEN NOT EXISTS (SELECT 1 FROM terceros WHERE id = NEW.tercero_id AND tipo_persona = 'persona_natural')
            BEGIN SELECT RAISE(ABORT, 'Un residente debe corresponder a una persona natural.'); END;

            CREATE TRIGGER terceros_residente_natural_guard
            BEFORE UPDATE OF tipo_persona ON terceros
            WHEN NEW.tipo_persona <> 'persona_natural' AND EXISTS (
                SELECT 1 FROM residentes WHERE tercero_id = NEW.id
            )
            BEGIN SELECT RAISE(ABORT, 'Una identidad con perfil de residente debe permanecer como persona natural.'); END;

            CREATE TRIGGER residentes_delete_guard BEFORE DELETE ON residentes
            BEGIN SELECT RAISE(ABORT, 'Los residentes no pueden eliminarse; utilice el estado.'); END;

            CREATE TRIGGER residentes_estado_guard
            BEFORE UPDATE OF estado ON residentes
            WHEN NEW.estado = 'inactivo' AND EXISTS (
                SELECT 1 FROM departamento_residentes WHERE residente_id = NEW.id AND estado = 'activa'
            )
            BEGIN SELECT RAISE(ABORT, 'No se puede inactivar un residente con ocupaciones activas.'); END;

            CREATE TRIGGER propietarios_tercero_required_guard
            BEFORE INSERT ON propietarios WHEN NEW.tercero_id IS NULL
            BEGIN SELECT RAISE(ABORT, 'Un propietario debe estar vinculado a una identidad global.'); END;

            CREATE TRIGGER propietarios_tercero_required_update_guard
            BEFORE UPDATE OF tercero_id ON propietarios WHEN NEW.tercero_id IS NULL
            BEGIN SELECT RAISE(ABORT, 'Un propietario debe estar vinculado a una identidad global.'); END;

            CREATE TRIGGER ocupaciones_delete_guard BEFORE DELETE ON departamento_residentes
            BEGIN SELECT RAISE(ABORT, 'El historial de ocupación no puede eliminarse.'); END;

            CREATE TRIGGER ocupaciones_shape_insert_guard
            BEFORE INSERT ON departamento_residentes
            WHEN (NEW.fecha_fin IS NOT NULL AND NEW.fecha_fin <= NEW.fecha_inicio)
              OR (NEW.estado = 'activa' AND NEW.fecha_fin IS NOT NULL)
              OR (NEW.estado = 'finalizada' AND NEW.fecha_fin IS NULL)
            BEGIN SELECT RAISE(ABORT, 'La fecha final y el estado de la ocupación son inconsistentes.'); END;

            CREATE TRIGGER ocupaciones_shape_update_guard
            BEFORE UPDATE ON departamento_residentes
            WHEN (NEW.fecha_fin IS NOT NULL AND NEW.fecha_fin <= NEW.fecha_inicio)
              OR (NEW.estado = 'activa' AND NEW.fecha_fin IS NOT NULL)
              OR (NEW.estado = 'finalizada' AND NEW.fecha_fin IS NULL)
            BEGIN SELECT RAISE(ABORT, 'La fecha final y el estado de la ocupación son inconsistentes.'); END;

            CREATE TRIGGER ocupaciones_active_insert_guard
            BEFORE INSERT ON departamento_residentes
            WHEN NEW.estado = 'activa' AND NOT EXISTS (
                SELECT 1 FROM residentes r
                JOIN departamentos d ON d.id = NEW.departamento_id AND d.edificio_id = NEW.edificio_id
                JOIN edificios e ON e.id = NEW.edificio_id
                WHERE r.id = NEW.residente_id
                  AND r.estado = 'activo'
                  AND d.estado = 'activo'
                  AND e.estado = 'activo'
            )
            BEGIN SELECT RAISE(ABORT, 'Una ocupación activa requiere edificio, departamento y residente activos.'); END;

            CREATE TRIGGER ocupaciones_active_update_guard
            BEFORE UPDATE ON departamento_residentes
            WHEN NEW.estado = 'activa' AND NOT EXISTS (
                SELECT 1 FROM residentes r
                JOIN departamentos d ON d.id = NEW.departamento_id AND d.edificio_id = NEW.edificio_id
                JOIN edificios e ON e.id = NEW.edificio_id
                WHERE r.id = NEW.residente_id
                  AND r.estado = 'activo'
                  AND d.estado = 'activo'
                  AND e.estado = 'activo'
            )
            BEGIN SELECT RAISE(ABORT, 'Una ocupación activa requiere edificio, departamento y residente activos.'); END;

            CREATE TRIGGER ocupaciones_update_guard
            BEFORE UPDATE ON departamento_residentes
            WHEN OLD.estado = 'finalizada'
              OR NEW.edificio_id IS NOT OLD.edificio_id
              OR NEW.departamento_id IS NOT OLD.departamento_id
              OR NEW.residente_id IS NOT OLD.residente_id
              OR NEW.tipo_ocupacion IS NOT OLD.tipo_ocupacion
              OR NEW.fecha_inicio IS NOT OLD.fecha_inicio
              OR NEW.nombre_residente IS NOT OLD.nombre_residente
              OR NEW.tipo_identificacion_snapshot IS NOT OLD.tipo_identificacion_snapshot
              OR NEW.identificacion_snapshot IS NOT OLD.identificacion_snapshot
            BEGIN SELECT RAISE(ABORT, 'Una ocupación finalizada o sus datos de origen son inmutables.'); END;

            CREATE TRIGGER ocupaciones_overlap_insert_guard
            BEFORE INSERT ON departamento_residentes
            WHEN EXISTS (
                SELECT 1 FROM departamento_residentes existing
                WHERE existing.departamento_id = NEW.departamento_id
                  AND existing.residente_id = NEW.residente_id
                  AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, '9999-12-31')
                  AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, '9999-12-31')
            )
            BEGIN SELECT RAISE(ABORT, 'La ocupación se superpone con el historial existente.'); END;

            CREATE TRIGGER ocupaciones_overlap_update_guard
            BEFORE UPDATE ON departamento_residentes
            WHEN EXISTS (
                SELECT 1 FROM departamento_residentes existing
                WHERE existing.departamento_id = NEW.departamento_id
                  AND existing.residente_id = NEW.residente_id
                  AND existing.id <> NEW.id
                  AND existing.fecha_inicio < COALESCE(NEW.fecha_fin, '9999-12-31')
                  AND NEW.fecha_inicio < COALESCE(existing.fecha_fin, '9999-12-31')
            )
            BEGIN SELECT RAISE(ABORT, 'La ocupación se superpone con el historial existente.'); END;

            CREATE TRIGGER ocupaciones_propietario_insert_guard
            BEFORE INSERT ON departamento_residentes
            WHEN NEW.tipo_ocupacion = 'propietario_ocupante' AND NOT EXISTS (
                SELECT 1 FROM residentes r
                JOIN propietarios p ON p.tercero_id = r.tercero_id
                JOIN departamento_propietarios t ON t.propietario_id = p.id
                WHERE r.id = NEW.residente_id
                  AND t.departamento_id = NEW.departamento_id
                  AND t.fecha_inicio <= NEW.fecha_inicio
                  AND (t.fecha_fin IS NULL OR t.fecha_fin > NEW.fecha_inicio)
            )
            BEGIN SELECT RAISE(ABORT, 'El propietario ocupante requiere una titularidad vigente al inicio.'); END;

            CREATE TRIGGER departamentos_ocupacion_guard
            BEFORE UPDATE OF estado ON departamentos
            WHEN NEW.estado = 'inactivo' AND EXISTS (
                SELECT 1 FROM departamento_residentes WHERE departamento_id = NEW.id AND estado = 'activa'
            )
            BEGIN SELECT RAISE(ABORT, 'No se puede inactivar un departamento con ocupaciones activas.'); END;
            SQL);
    }

    private function dropIntegrityGuards(bool $pgsql, string $schema): void
    {
        if (! $pgsql) {
            foreach ([
                'residentes_natural_guard',
                'residentes_natural_update_guard',
                'terceros_residente_natural_guard',
                'residentes_delete_guard',
                'residentes_estado_guard',
                'propietarios_tercero_required_guard',
                'propietarios_tercero_required_update_guard',
                'ocupaciones_delete_guard',
                'ocupaciones_shape_insert_guard',
                'ocupaciones_shape_update_guard',
                'ocupaciones_active_insert_guard',
                'ocupaciones_active_update_guard',
                'ocupaciones_update_guard',
                'ocupaciones_overlap_insert_guard',
                'ocupaciones_overlap_update_guard',
                'ocupaciones_propietario_insert_guard',
                'departamentos_ocupacion_guard',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS departamentos_ocupacion_guard ON "{$schema}"."departamentos";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_departamento_ocupado"();
            DROP TRIGGER IF EXISTS ocupaciones_integridad_guard ON "{$schema}"."departamento_residentes";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_departamento_ocupacion"();
            DROP TRIGGER IF EXISTS residentes_history_guard ON "{$schema}"."residentes";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_residente_history"();
            DROP TRIGGER IF EXISTS residentes_natural_guard ON "{$schema}"."residentes";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_residente_natural"();
            DROP TRIGGER IF EXISTS terceros_residente_natural_guard ON "{$schema}"."terceros";
            DROP FUNCTION IF EXISTS "{$schema}"."guard_tercero_residente_natural"();
            SQL);
    }
};
